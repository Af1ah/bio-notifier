<?php

namespace App\Services\Attendance;

use App\Models\AttendanceDay;
use App\Models\SalarySlip;
use App\Services\Payroll\SalarySlipService;
use Carbon\Carbon;
use Illuminate\Validation\ValidationException;

class HolidayAttendanceService
{
    /** Reconcile existing results, leaving unrelated attendance and approvals intact. */
    public function reconcile(array $dates): int
    {
        $calculator = app(AttendanceCalculationService::class);
        $periods = [];
        $changed = 0;
        AttendanceDay::query()->whereIn('work_date', array_unique($dates))
            ->with('user')->chunkById(100, function ($days) use ($calculator, &$periods, &$changed) {
                foreach ($days as $day) {
                    if (! $day->user || $calculator->isHoliday($day->user, $day->work_date) === ($day->status === 'holiday')) {
                        continue;
                    }
                    $calculator->calculate($day->user, $day->work_date);
                    $periods[$day->user_id][$day->work_date->format('Y-m-01')] = $day->user;
                    $changed++;
                }
            });

        foreach ($periods as $userId => $months) {
            foreach ($months as $month => $user) {
                $slip = SalarySlip::where('user_id', $userId)->whereDate('period_start', $month)->first();
                if (! $slip) {
                    continue;
                }
                // Preserve reviewed, approved and paid financial snapshots.
                $slip->update(['calculation_snapshot' => array_merge($slip->calculation_snapshot ?? [], [
                    'needs_recalculation' => true,
                    'recalculation_reason' => 'Holiday changes affected attendance. Recalculate this payroll period before proceeding.',
                ])]);
                if ($slip->status === 'draft') {
                    try {
                        app(SalarySlipService::class)->generate($user, Carbon::parse($month));
                    } catch (ValidationException $exception) {
                        $snapshot = $slip->fresh()->calculation_snapshot;
                        $snapshot['recalculation_reason'] = implode(' ', $exception->validator->errors()->all());
                        $slip->update(['calculation_snapshot' => $snapshot]);
                    }
                }
            }
        }

        return $changed;
    }
}
