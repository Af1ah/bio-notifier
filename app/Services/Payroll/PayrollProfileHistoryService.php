<?php

namespace App\Services\Payroll;

use App\Models\EmployeePayrollProfile;
use App\Models\SalarySlip;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class PayrollProfileHistoryService
{
    public const MONEY = ['basic_salary_minor', 'housing_allowance_minor', 'transport_allowance_minor', 'other_allowance_minor', 'fixed_deduction_minor', 'overtime_hourly_rate_minor'];

    public function forDate(User $user, Carbon|string $date): ?EmployeePayrollProfile
    {
        $profile = $user->payrollProfile()->first();
        if (! $profile || ! $profile->payroll_enabled) return $profile;
        $revision = DB::table('payroll_profile_revisions')->where('employee_payroll_profile_id', $profile->id)->whereDate('effective_from', '<=', Carbon::parse($date)->toDateString())->latest('effective_from')->first();
        if (! $revision) return $profile;
        $historical = new EmployeePayrollProfile; $historical->exists = true; $historical->setRawAttributes((array) json_decode($revision->snapshot, true), true); $historical->id = $profile->id;
        return $historical;
    }

    public function beforeSave(EmployeePayrollProfile $profile): void
    {
        if (! $profile->exists || ! $profile->isDirty(self::MONEY) || ! $profile->payroll_enabled) return;
        $date = Carbon::parse($profile->effective_from)->startOfDay();
        if (! $date->isSameDay($date->copy()->startOfMonth())) throw ValidationException::withMessages(['effective_from' => 'Salary changes must start on the first day of a month.']);
        if (SalarySlip::where('user_id', $profile->user_id)->whereDate('period_end', '>=', $date)->whereIn('status', ['reviewed', 'approved', 'paid'])->exists()) throw ValidationException::withMessages(['effective_from' => 'This change overlaps closed payroll.']);
        DB::table('payroll_profile_revisions')->insertOrIgnore(['employee_payroll_profile_id' => $profile->id, 'effective_from' => Carbon::parse($profile->getOriginal('effective_from'))->toDateString(), 'snapshot' => json_encode($profile->getRawOriginal()), 'created_at' => now(), 'updated_at' => now()]);
    }

    public function afterSave(EmployeePayrollProfile $profile, ?User $actor): void
    {
        if (! $profile->payroll_enabled || ! $profile->effective_from) return;
        DB::table('payroll_profile_revisions')->updateOrInsert(['employee_payroll_profile_id' => $profile->id, 'effective_from' => $profile->effective_from->toDateString()], ['snapshot' => json_encode($profile->getAttributes()), 'changed_by_user_id' => $actor?->id, 'updated_at' => now(), 'created_at' => now()]);
    }
}
