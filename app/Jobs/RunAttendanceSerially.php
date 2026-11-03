<?php

namespace App\Jobs;

use App\Models\AttendanceCalculationRun;
use App\Models\AttendanceRunRequest;
use App\Models\Organisation;
use App\Services\Attendance\AttendanceCalculationService;
use App\Services\Attendance\AttendanceRunQueue;
use App\Services\Tenancy\TenantContext;
use Illuminate\Contracts\Cache\Repository;
use Illuminate\Contracts\Queue\ShouldBeUniqueUntilProcessing;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\TimeoutExceededException;
use Illuminate\Support\Facades\DB;

class RunAttendanceSerially implements ShouldBeUniqueUntilProcessing, ShouldQueue
{
    use Queueable;

    public int $tries = 0;

    public int $maxExceptions = 3;

    public int $timeout = 60;

    public bool $failOnTimeout = true;

    public int $uniqueFor = 300;

    public function __construct(public int $requestId) {}

    public function uniqueId(): string
    {
        return (string) $this->requestId;
    }

    public function uniqueVia(): Repository
    {
        return AttendanceRunQueue::cache();
    }

    public function backoff(): array
    {
        return [15, 60];
    }

    public function handle(AttendanceRunQueue $queue, AttendanceCalculationService $calculator): void
    {
        $lock = AttendanceRunQueue::lock();
        if (! $lock->get()) {
            $this->release(15);

            return;
        }
        try {
            $request = AttendanceRunRequest::find($this->requestId);
            $first = AttendanceRunRequest::pendingInOrder()->value('id');
            if (! $request || ! in_array($request->state, ['queued', 'running'], true) || (int) $first !== $request->id) {
                $queue->dispatchNext();

                return;
            }
            $organisation = Organisation::find($request->organisation_id);
            if (! $organisation) {
                $request->update(['state' => 'failed', 'last_error' => 'Organisation no longer exists.']);
            } else {
                $request->update(['state' => 'running']);
                TenantContext::run($organisation, function () use ($request, $queue, $calculator, $organisation, $lock) {
                    $run = $queue->tenantRun($request);
                    $days = (int) $request->from_date->diffInDays($request->to_date) + 1;
                    $total = count($request->user_ids) * $days;
                    $started = microtime(true);
                    // Bound each job; later jobs keep working on this tenant until its run is finished.
                    for ($step = 0; $request->cursor < $total && $step < 10 && microtime(true) - $started < 20; $step++) {
                        if (! $lock->refresh()) {
                            throw new \RuntimeException('Attendance runner lost its execution lock.');
                        }
                        $employeeId = $request->user_ids[intdiv($request->cursor, $days)];
                        $date = $request->from_date->copy()->addDays($request->cursor % $days)->toDateString();
                        (new RecalculateAttendanceChunk($organisation, $run->id, [$employeeId]))->process($calculator, $date);
                        if (! DB::table('attendance_calculation_results')->where('attendance_calculation_run_id', $run->id)->where('employee_id_snapshot', $employeeId)->whereDate('work_date', $date)->exists()) {
                            throw new \RuntimeException('Attendance run requires reconciliation before continuing.');
                        }
                        // The tenant result commits first; replay after a crash is idempotent.
                        $request->update(['cursor' => $request->cursor + 1, 'failures' => 0]);
                    }
                    if ($request->cursor >= $total) {
                        $request->update(['state' => 'completed', 'last_error' => null]);
                    }
                });
            }
        } catch (\Throwable $exception) {
            if (isset($request) && $request) {
                $request->increment('failures');
                $request->update(['last_error' => 'Calculation interrupted; automatic retry pending.']);
                if ($request->fresh()->failures >= 3) {
                    $this->failed($exception);
                }
            }
            throw $exception;
        } finally {
            $lock->release();
        }
        $queue->dispatchNext();
    }

    public function failed(?\Throwable $exception): void
    {
        if ($exception instanceof TimeoutExceededException && tenancy()->initialized) {
            DB::connection()->rollBack(toLevel: 0);
        }
        // A timeout may retain the execution lease briefly. Never clear another worker's lease.
        $request = AttendanceRunRequest::find($this->requestId);
        if ($request && in_array($request->state, ['queued', 'running'], true)) {
            $request->update(['state' => 'failed', 'last_error' => 'Calculation stopped after repeated failures or a timeout. Submit a rerun to retry.']);
            if ($organisation = Organisation::find($request->organisation_id)) {
                TenantContext::run($organisation, fn () => AttendanceCalculationRun::where('run_key', $request->run_key)->first()?->update(['state' => 'failed', 'failed' => 1, 'last_error' => $request->last_error]));
            }
        }
        app(AttendanceRunQueue::class)->dispatchNext();
    }
}
