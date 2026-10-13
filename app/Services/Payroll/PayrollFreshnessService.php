<?php

namespace App\Services\Payroll;

use App\Models\SalarySlip;
use Carbon\CarbonInterface;

class PayrollFreshnessService
{
    public function invalidate(int $userId, CarbonInterface $date): void
    {
        foreach (SalarySlip::where('user_id', $userId)->whereDate('period_start', '<=', $date)->whereDate('period_end', '>=', $date)->lockForUpdate()->get() as $slip) {
            $slip->update(['calculation_snapshot' => array_merge($slip->calculation_snapshot ?? [], [
                'needs_recalculation' => true,
                'recalculation_reason' => 'Attendance evidence or its decision changed. Review and recalculate draft payroll; finalized payroll requires an adjustment.',
            ])]);
        }
    }
}
