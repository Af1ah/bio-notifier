<?php

namespace App\Jobs;

use App\Models\AttendanceCalculationRun;
use App\Models\Organisation;
use App\Models\User;
use App\Services\Attendance\AttendanceCalculationService;
use Carbon\CarbonPeriod;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

class RecalculateAttendanceChunk implements ShouldQueue
{
    use Queueable;

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
            $run->update(['state' => 'running']);
            foreach (User::whereIn('id', $this->userIds)->cursor() as $user) {
                foreach (CarbonPeriod::create($run->from_date, $run->to_date) as $date) {
                    $calculator->calculate($user, $date);
                    $run->increment('completed');
                }
            }
            if ($run->fresh()->completed + $run->failed >= $run->total) {
                $run->update(['state' => $run->failed ? 'completed_with_errors' : 'completed']);
            }
        } catch (\Throwable $e) {
            if (isset($run)) {
                $run->increment('failed');
                $run->update(['last_error' => $e->getMessage(), 'state' => 'failed']);
            }throw $e;
        } finally {
            tenancy()->end();
        }
    }
}
