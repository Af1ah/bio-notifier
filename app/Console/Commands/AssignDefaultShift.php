<?php

namespace App\Console\Commands;

use App\Models\Organisation;
use App\Models\Schedule;
use App\Services\Attendance\ShiftAssignmentService;
use Carbon\Carbon;
use Illuminate\Console\Command;
use Illuminate\Validation\ValidationException;

class AssignDefaultShift extends Command
{
    protected $signature = 'attendance:assign-default-shift {tenant : Organisation ID or shortname} {schedule : Tenant schedule ID} {from : First applicable date} {--until= : Last applicable date; blank means ongoing}';

    protected $description = 'Assign an existing effective shift as the tenant default for a bounded date range';

    public function handle(ShiftAssignmentService $service): int
    {
        $tenant = Organisation::where('id', $this->argument('tenant'))->orWhere('shortname', $this->argument('tenant'))->firstOrFail();
        $from = Carbon::parse($this->argument('from'))->toDateString();
        $until = $this->option('until') ? Carbon::parse($this->option('until'))->toDateString() : null;
        tenancy()->initialize($tenant);
        try {
            $schedule = Schedule::where('status', true)->findOrFail($this->argument('schedule'));
            $set = $service->setDefault($schedule, $from, $until);
            $this->info("Default shift assignment {$set->id} saved for {$tenant->shortname}: {$from} to ".($until ?? 'ongoing').'. Recalculate attendance for this range.');

            return self::SUCCESS;
        } catch (ValidationException $exception) {
            $this->error(collect($exception->errors())->flatten()->first());

            return self::FAILURE;
        } finally {
            tenancy()->end();
        }
    }
}
