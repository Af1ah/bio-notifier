<?php

namespace App\Services\Attendance;

use App\Jobs\RunAttendanceSerially;
use App\Models\AttendanceCalculationRun;
use App\Models\AttendanceRunRequest;
use App\Models\Organisation;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Cache\DatabaseLock;
use Illuminate\Cache\DatabaseStore;
use Illuminate\Cache\Repository;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

final class AttendanceRunQueue
{
    public static function lock(string $name = 'execute', int $seconds = 120): DatabaseLock
    {
        // Explicit central connection: tenant cache tags must never split this lock.
        return new DatabaseLock(DB::connection(config('tenancy.database.central_connection')), 'cache_locks', 'attendance-serial:'.$name, $seconds);
    }

    public static function cache(): Repository
    {
        return new Repository(new DatabaseStore(DB::connection(config('tenancy.database.central_connection')), 'cache', 'attendance-serial:', 'cache_locks'));
    }

    public function submit(Organisation $organisation, Carbon $from, Carbon $to, array $selected = [], bool $nightly = false): ?AttendanceCalculationRun
    {
        abort_unless((string) tenant()?->getTenantKey() === (string) $organisation->getTenantKey(), 403);

        return self::lock('submit:'.$organisation->getTenantKey(), 30)->block(5, function () use ($organisation, $from, $to, $selected, $nightly) {
            if ($nightly && AttendanceRunRequest::where('organisation_id', $organisation->id)->whereDate('nightly_work_date', $to->toDateString())->exists()) {
                $this->dispatchNext();

                return null;
            }
            // Repair the tenant status if a terminated worker only committed its central failure record.
            $failedKeys = AttendanceRunRequest::where('organisation_id', $organisation->id)->where('state', 'failed')->pluck('run_key');
            if ($failedKeys->isNotEmpty()) {
                AttendanceCalculationRun::whereIn('run_key', $failedKeys)->whereIn('state', ['queued', 'running'])->get()->each->update(['state' => 'failed', 'failed' => 1, 'last_error' => 'Calculation interrupted. Submit a rerun to retry.']);
            }
            if (AttendanceCalculationRun::whereIn('state', ['queued', 'running'])->exists()
                || AttendanceRunRequest::where('organisation_id', $organisation->id)->whereIn('state', ['queued', 'running'])->exists()) {
                throw ValidationException::withMessages(['from_date' => 'Another recalculation is already queued or running for this tenant.']);
            }
            if ($to->lt($from) || $from->diffInDays($to) > 30) {
                throw ValidationException::withMessages(['to_date' => 'Choose a valid range of up to 31 days.']);
            }
            $ids = User::employees()->when($selected, fn ($query) => $query->whereIn('id', $selected))->orderBy('id')->pluck('id')->all();
            if (count($ids) * ($from->diffInDays($to) + 1) > 10000) {
                throw ValidationException::withMessages(['user_ids' => 'Maximum workload is 10,000 employee-days.']);
            }
            // Persist the work before dispatching; the minute recovery task can repair a lost queue signal.
            $request = AttendanceRunRequest::create([
                'organisation_id' => $organisation->id, 'run_key' => Str::uuid(),
                'nightly_work_date' => $nightly ? $to->toDateString() : null,
                'from_date' => $from->toDateString(), 'to_date' => $to->toDateString(), 'user_ids' => $ids,
                'filters' => ['source' => $nightly ? 'nightly' : 'manual', 'user_ids' => $selected, 'requested_by_user_id' => $nightly ? null : auth('web')->id()],
            ]);
            try {
                return $this->tenantRun($request);
            } finally {
                $this->dispatchNext();
            }
        });
    }

    public function tenantRun(AttendanceRunRequest $request): AttendanceCalculationRun
    {
        abort_unless((string) tenant()?->getTenantKey() === (string) $request->organisation_id, 403);
        $run = AttendanceCalculationRun::firstOrCreate(['run_key' => $request->run_key], [
            'from_date' => $request->from_date->toDateString(), 'to_date' => $request->to_date->toDateString(),
            'requested_by_user_id' => $request->filters['requested_by_user_id'] ?? null,
            'filters' => $request->filters, 'state' => $request->user_ids ? 'queued' : 'completed',
            'total' => count($request->user_ids) * ($request->from_date->diffInDays($request->to_date) + 1),
        ]);
        $request->update(['tenant_run_id' => $run->id]);

        return $run;
    }

    public function dispatchNext(): void
    {
        $request = AttendanceRunRequest::pendingInOrder()->first();
        if ($request) {
            RunAttendanceSerially::dispatch($request->id);
        }
    }
}
