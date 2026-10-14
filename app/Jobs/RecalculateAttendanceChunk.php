<?php

namespace App\Jobs;

use App\Models\AttendanceCalculationRun;
use App\Models\Organisation;
use App\Models\User;
use App\Services\Attendance\AttendanceCalculationService;
use Carbon\CarbonPeriod;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\DB;

class RecalculateAttendanceChunk implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public int $timeout = 60;

    public function backoff(): array
    {
        return [15, 60];
    }

    public function failed(?\Throwable $exception): void
    {
        tenancy()->initialize($this->tenant);
        try {
            AttendanceCalculationRun::whereKey($this->runId)->update(['state' => 'failed', 'failed' => 1, 'last_error' => 'Queue job exhausted its retries or timed out. Retry the failed job.']);
        } finally {
            tenancy()->end();
        }
    }

    /**
     * Create a new job instance.
     */
    public function __construct(public Organisation $tenant, public int $runId, public array $userIds)
    {
        // Use the connection's default queue, like webhook and application jobs.
    }

    /**
     * Execute the job.
     */
    public function handle(AttendanceCalculationService $calculator): void
    {
        tenancy()->initialize($this->tenant);
        try {
            $run = AttendanceCalculationRun::findOrFail($this->runId);
            if ($run->from_date->gt($run->to_date)) {
                $run->update(['state' => 'failed', 'total' => 0, 'last_error' => 'The end date must be on or after the start date. Submit a new calculation with a valid date range.']);

                return;
            }
            if ($run->state === 'needs_reconciliation') {
                return;
            }
            $run->update(['state' => 'running']);
            foreach ($this->userIds as $employeeId) {
                foreach (CarbonPeriod::create($run->from_date, $run->to_date) as $date) {
                    DB::transaction(function () use ($run, $employeeId, $date, $calculator) {
                        $locked = AttendanceCalculationRun::lockForUpdate()->findOrFail($run->id);
                        $key = ['attendance_calculation_run_id' => $run->id, 'employee_id_snapshot' => $employeeId, 'work_date' => $date->toDateString()];
                        if (DB::table('attendance_calculation_results')->where($key)->exists()) {
                            return;
                        }
                        $user = User::lockForUpdate()->find($employeeId);
                        $eligible = $user && User::employees()->whereKey($employeeId)->exists();
                        if ($eligible) {
                            $calculator->calculate($user, $date);
                            DB::table('attendance_calculation_items')->insertOrIgnore(['attendance_calculation_run_id' => $run->id, 'user_id' => $employeeId, 'work_date' => $date->toDateString()]);
                        }
                        DB::table('attendance_calculation_results')->insert($key + ['outcome' => $eligible ? 'completed' : 'skipped',
                            'reason' => $eligible ? null : ($user ? 'employee_excluded' : 'employee_removed'), 'created_at' => now(), 'updated_at' => now()]);
                        $results = DB::table('attendance_calculation_results')->where('attendance_calculation_run_id', $run->id);
                        $completed = (clone $results)->where('outcome', 'completed')->count() + $locked->legacy_completed_credit;
                        $skipped = (clone $results)->where('outcome', 'skipped')->count();
                        $locked->update(['completed' => $completed, 'skipped' => $skipped, 'failed' => 0, 'last_error' => null,
                            'state' => $completed + $skipped >= $locked->total ? ($skipped ? 'completed_with_skips' : 'completed') : 'running']);
                    }, 3);
                }
            }
            DB::transaction(function () use ($run) {
                $locked = AttendanceCalculationRun::lockForUpdate()->findOrFail($run->id);
                $results = DB::table('attendance_calculation_results')->where('attendance_calculation_run_id', $run->id);
                $completed = (clone $results)->where('outcome', 'completed')->count() + $locked->legacy_completed_credit;
                $skipped = (clone $results)->where('outcome', 'skipped')->count();
                $locked->update(['completed' => $completed, 'skipped' => $skipped, 'state' => $completed + $skipped >= $locked->total ? ($skipped ? 'completed_with_skips' : 'completed') : 'running']);
            });
        } catch (\Throwable $e) {
            if (isset($run)) {
                $run->update(['failed' => 1, 'last_error' => 'Calculation failed; inspect the failed queue job and retry.', 'state' => 'failed']);
            }throw $e;
        } finally {
            tenancy()->end();
        }
    }
}
