<?php

namespace App\Console\Commands;

use App\Jobs\RecalculateAttendanceChunk;
use App\Models\AttendanceCalculationRun;
use App\Models\Organisation;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use Illuminate\Support\Str;

class RecalculateNightlyAttendance extends Command
{
    protected $signature = 'attendance:recalculate-nightly
        {--date= : Work date to calculate; defaults to yesterday in the attendance timezone}
        {--tenant=* : Optional organisation IDs or shortnames}';

    protected $description = 'Queue the previous workday attendance calculation for every tenant';

    public function handle(): int
    {
        $timezone = config('attendance.timezone', config('app.timezone'));
        $workDate = $this->option('date')
            ? CarbonImmutable::parse($this->option('date'), $timezone)->toDateString()
            : CarbonImmutable::now($timezone)->subDay()->toDateString();
        $tenantFilters = array_values(array_filter($this->option('tenant')));
        $tenants = Organisation::query()
            ->when($tenantFilters, fn ($query) => $query->where(function ($query) use ($tenantFilters) {
                $query->whereIn('id', $tenantFilters)->orWhereIn('shortname', $tenantFilters);
            }))
            ->cursor();
        $queued = 0;
        $skipped = 0;

        foreach ($tenants as $tenant) {
            tenancy()->initialize($tenant);

            try {
                if (AttendanceCalculationRun::query()->whereIn('state', ['queued', 'running'])->exists()) {
                    $this->warn("Skipped {$tenant->name}: another attendance recalculation is active.");
                    $skipped++;

                    continue;
                }

                $userIds = User::query()->pluck('id')->all();
                $run = AttendanceCalculationRun::create([
                    'run_key' => Str::uuid(),
                    'from_date' => $workDate,
                    'to_date' => $workDate,
                    'filters' => ['source' => 'nightly'],
                    'state' => $userIds ? 'queued' : 'completed',
                    'total' => count($userIds),
                ]);

                foreach (array_chunk($userIds, 100) as $chunk) {
                    RecalculateAttendanceChunk::dispatch($tenant, $run->id, $chunk);
                }

                $this->info("Queued {$tenant->name}: {$run->total} employee-days for {$workDate}.");
                $queued++;
            } finally {
                tenancy()->end();
            }
        }

        $this->info("Nightly attendance dispatch finished: {$queued} queued, {$skipped} skipped.");

        return self::SUCCESS;
    }
}
