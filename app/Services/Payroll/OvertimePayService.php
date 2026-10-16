<?php

namespace App\Services\Payroll;

use App\Models\AttendanceDay;
use App\Models\AttendanceOccurrence;
use App\Models\EmployeePayrollProfile;
use App\Models\ShiftRuleRevision;
use Illuminate\Support\Collection;
use Illuminate\Validation\ValidationException;

class OvertimePayService
{
    public function capture(AttendanceDay $day, ?AttendanceOccurrence $slot): array
    {
        $rule = $slot?->ruleRevision;
        $daily = ShiftRuleRevision::find($day->explanation['daily_policy_rule_revision_id'] ?? null);
        if (! ($rule?->snapshot['overtime_enabled'] ?? true) || ! ($daily?->snapshot['overtime_enabled'] ?? true)) {
            throw ValidationException::withMessages(['approval' => 'Overtime is disabled by the effective shift rules.']);
        }
        $profile = app(PayrollProfileHistoryService::class)->forDate($day->user, $day->work_date);
        $rate = $rule?->snapshot['overtime_hourly_rate_minor'] ?? null;
        $currency = $rate !== null ? ($rule->snapshot['overtime_currency'] ?? 'INR') : ($profile?->currency ?? 'INR');
        if ($profile && $currency !== $profile->currency) {
            throw ValidationException::withMessages(['approval' => 'Shift overtime currency must match the employee payroll currency.']);
        }
        if ($rate === null && $profile) {
            $rate = (int) $profile->overtime_hourly_rate_minor ?: ($profile->pay_basis === 'hourly' ? (int) $profile->basic_salary_minor : 0);
        }

        return ['hourly_rate_minor' => $rate, 'currency' => $currency, 'source' => isset($rule?->snapshot['overtime_hourly_rate_minor']) ? 'shift' : 'employee', 'shift_rule_revision_id' => $rule?->id];
    }

    public function calculate(Collection $days, EmployeePayrollProfile $profile): array
    {
        $fallback = (int) $profile->overtime_hourly_rate_minor ?: ($profile->pay_basis === 'hourly' ? (int) $profile->basic_salary_minor : 0);
        $rows = [];
        $amount = 0;
        foreach ($days as $day) {
            $remaining = (int) $day->approved_overtime_minutes;
            $approvals = $day->approvals()->where('type', 'overtime')->where('state', 'approved')->where('calculation_revision', $day->calculation_revision)->orderBy('id')->get();
            foreach ($approvals as $approval) {
                $rate = $approval->proposal['pay_rate'] ?? null;
                if (! $rate) {
                    continue;
                }
                $minutes = min($remaining, (int) ($approval->proposal['minutes'] ?? $approval->occurrence?->approved_overtime_minutes ?? $remaining));
                if ($rate['currency'] !== $profile->currency) {
                    throw ValidationException::withMessages(['payroll' => 'Approved overtime currency does not match the payroll currency.']);
                }
                $hourly = $rate['hourly_rate_minor'] ?? $fallback;
                $pay = intdiv((int) $hourly * $minutes + 30, 60);
                $rows[] = ['date' => $day->work_date->toDateString(), 'approval_id' => $approval->id, 'schedule_id' => $approval->occurrence?->schedule_id, 'shift' => $approval->occurrence?->schedule?->name, 'minutes' => $minutes, 'hourly_rate_minor' => (int) $hourly, 'currency' => $rate['currency'], 'amount_minor' => $pay, 'source' => $rate['source']];
                $amount += $pay;
                $remaining -= $minutes;
            }
            if ($remaining > 0) {
                $rows[] = ['date' => $day->work_date->toDateString(), 'minutes' => $remaining, 'hourly_rate_minor' => $fallback, 'currency' => $profile->currency, 'amount_minor' => null, 'source' => 'legacy_employee_rate'];
            }
        }
        // Preserve the previous aggregate-rounding convention for unsnapshotted legacy overtime.
        $legacy = array_sum(array_column(array_filter($rows, fn ($row) => $row['source'] === 'legacy_employee_rate'), 'minutes'));
        $legacyTotal = intdiv($fallback * $legacy + 30, 60);
        $legacyRows = array_keys(array_filter($rows, fn ($row) => $row['source'] === 'legacy_employee_rate'));
        $distributed = 0;
        foreach ($legacyRows as $i => $key) {
            $pay = $i === count($legacyRows) - 1 ? $legacyTotal - $distributed : intdiv($fallback * $rows[$key]['minutes'] + 30, 60);
            $rows[$key]['amount_minor'] = $pay;
            $distributed += $pay;
        }
        $amount += $legacyTotal;

        return ['amount_minor' => $amount, 'rows' => $rows];
    }
}
