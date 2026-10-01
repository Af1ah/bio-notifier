<?php

namespace App\Console\Commands;

use App\Models\Organisation;
use App\Models\User;
use App\Services\Attendance\AttendanceCalculationService;
use Carbon\Carbon;
use Carbon\CarbonPeriod;
use Illuminate\Console\Command;

class RecalculateAttendance extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'attendance:recalculate {tenant : Organisation ID or shortname} {from} {to} {--user=* : Tenant user IDs}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Recalculate a bounded tenant attendance range';

    /**
     * Execute the console command.
     */
    public function handle(AttendanceCalculationService $calculator): int
    {
        $tenant = Organisation::query()->where('id', $this->argument('tenant'))->orWhere('shortname', $this->argument('tenant'))->firstOrFail();
        $from = Carbon::parse($this->argument('from'));
        $to = Carbon::parse($this->argument('to'));
        if ($to->lt($from)) {
            $this->error('The end date must be on or after the start date.');

            return self::FAILURE;
        }
        if ($from->diffInDays($to) > 30) {
            $this->error('Maximum range is 31 days.');

            return self::FAILURE;
        }tenancy()->initialize($tenant);
        try {
            $users = User::query()->when($this->option('user'), fn ($q, $ids) => $q->whereIn('id', $ids))->cursor();
            $count = 0;
            foreach ($users as $user) {
                foreach (CarbonPeriod::create($from, $to) as $date) {
                    $calculator->calculate($user, $date);
                    $count++;
                }
            }$this->info("Calculated {$count} employee-days.");

            return self::SUCCESS;
        } finally {
            tenancy()->end();
        }
    }
}
