<?php

namespace App\Console\Commands;

use App\Models\Organisation;
use App\Services\Attendance\AttendanceRunQueue;
use App\Services\Support\TenantTimezone;
use Carbon\Carbon;
use Illuminate\Console\Command;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Validation\ValidationException;

class RecalculateNightlyAttendance extends Command
{
    protected $signature = 'attendance:recalculate-nightly
        {--date= : Work date to calculate; defaults to yesterday in each organisation timezone}
        {--tenant=* : Optional organisation IDs or shortnames}
        {--due : Only queue organisations whose local nightly time has passed}';

    protected $description = 'Queue nightly attendance serially, one organisation at a time';

    public function handle(AttendanceRunQueue $queue): int
    {
        $time = config('attendance.nightly_time', '02:00');
        if (! preg_match('/^(?:[01][0-9]|2[0-3]):[0-5][0-9]$/', $time)) {
            $this->error('Attendance nightly time must use HH:MM.');

            return self::FAILURE;
        }
        if ($this->option('date') && ! validator(['date' => $this->option('date')], ['date' => 'date_format:Y-m-d'])->passes()) {
            $this->error('Work date must use YYYY-MM-DD.');

            return self::FAILURE;
        }
        $filters = array_values(array_filter($this->option('tenant')));
        $tenants = Organisation::query()->when($filters, fn ($query) => $query->where(fn ($query) => $query->whereIn('id', $filters)->orWhereIn('shortname', $filters)))->orderBy('id')->cursor();
        foreach ($tenants as $tenant) {
            tenancy()->initialize($tenant);
            try {
                $now = Carbon::now(TenantTimezone::get());
                if ($this->option('due') && $now->format('H:i') < $time) {
                    continue;
                }
                $to = $this->option('date') ? Carbon::parse($this->option('date'), TenantTimezone::get()) : $now->copy()->subDay()->startOfDay();
                // Revisit overnight shifts and late deliveries without reopening older months.
                $from = $this->option('date') ? $to->copy() : $to->copy()->subDays(2);
                $run = $queue->submit($tenant, $from, $to, nightly: true);
                if ($run) {
                    $this->info("Queued {$tenant->shortname}: {$run->total} employee-days.");
                }
            } catch (ValidationException|LockTimeoutException $exception) {
                // A manual run is active; the next scheduler tick retries this tenant.
                $this->line("Deferred {$tenant->shortname}: a recalculation is already queued or running.");
            } finally {
                tenancy()->end();
            }
        }
        $queue->dispatchNext();

        return self::SUCCESS;
    }
}
