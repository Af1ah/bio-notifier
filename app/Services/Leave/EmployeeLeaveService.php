<?php

namespace App\Services\Leave;

use App\Models\AttendanceDay;
use App\Models\EmployeeLeave;
use App\Models\LeaveType;
use App\Models\SalarySlip;
use App\Models\User;
use App\Services\Attendance\AttendanceCalculationService;
use App\Services\Attendance\ShiftAssignmentResolver;
use App\Services\Payroll\PayrollFreshnessService;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

class EmployeeLeaveService
{
    public function save(?EmployeeLeave $leave, array $data, User $actor): EmployeeLeave
    {
        abort_unless((int) $actor->privilege === 14, 403);
        $v = Validator::make($data, ['user_id' => 'required|integer|exists:users,id', 'leave_type_id' => 'required|integer|exists:leave_types,id',
            'leave_date' => 'required|date', 'duration' => 'required|in:full,half', 'state' => 'required|in:pending,approved,rejected,cancelled',
            'deduction_override_minor' => 'nullable|integer|min:0', 'notes' => 'nullable|string|max:1000'])->validate();

        return DB::transaction(function () use ($leave, $v, $actor) {
            $user = User::employees()->lockForUpdate()->findOrFail($v['user_id']);
            if ($leave) {
                $leave = EmployeeLeave::lockForUpdate()->findOrFail($leave->id);
                if ((int) $leave->user_id !== (int) $user->id) {
                    throw ValidationException::withMessages(['user_id' => 'Employee cannot change on an existing leave record.']);
                }
                $unchanged = collect($v)->every(function ($value, $key) use ($leave) {
                    return $key === 'leave_date' ? $leave->leave_date->toDateString() === Carbon::parse($value)->toDateString() : $leave->getAttribute($key) == $value;
                });
                if ($unchanged && ($leave->balance_managed || ! app(LeavePolicyService::class)->resolve($user, $leave->leave_type_id, $leave->leave_date))) {
                    return $leave->fresh();
                }
                $this->assertPeriodOpen($user, $leave->leave_date);
            }
            $date = Carbon::parse($v['leave_date'])->startOfDay();
            $this->assertPeriodOpen($user, $date);
            $duplicate = EmployeeLeave::where('user_id', $user->id)->whereDate('leave_date', $date)->when($leave, fn ($q) => $q->whereKeyNot($leave->id))->exists();
            if ($duplicate) {
                throw ValidationException::withMessages(['leave_date' => 'A leave record already exists on this date. Edit that record.']);
            }
            $profile = $user->payrollProfile()->first();
            if (($profile?->employment_starts_on && $date->lt($profile->employment_starts_on)) || ($profile?->employment_ends_on && $date->gt($profile->employment_ends_on))) {
                throw ValidationException::withMessages(['leave_date' => 'Leave must fall within employment dates.']);
            }
            if (in_array($v['state'], ['pending', 'approved'])) {
                $calendar = app(AttendanceCalculationService::class);
                $set = app(ShiftAssignmentResolver::class)->resolve($user, $date);
                $isOff = $set && ! $set->slots->contains(fn ($slot) => $slot->schedule?->activeRuleRevision($date)?->weekdays()->where('weekday', $date->dayOfWeek)->exists());
                $day = AttendanceDay::where('user_id', $user->id)->whereDate('work_date', $date)->first();
                if ($calendar->isHoliday($user, $date) || $isOff || in_array($day?->status, ['holiday', 'off'])) {
                    throw ValidationException::withMessages(['leave_date' => 'Holiday and rostered off days do not consume leave. Choose a working date.']);
                }
                if (($day?->approved_worked_minutes ?? 0) > 0 && ($v['duration'] === 'full' || $day->status !== 'half_day')) {
                    throw ValidationException::withMessages(['duration' => 'Leave overlaps approved work. Half-day leave requires a verified half-day attendance result.']);
                }
            }
            $balances = app(LeaveBalanceService::class);
            if ($leave) {
                $balances->release($leave, $actor);
                app(PayrollFreshnessService::class)->invalidate($user->id, $leave->leave_date);
            }
            $revision = app(LeavePolicyService::class)->resolve($user, $v['leave_type_id'], $date);
            if (in_array($v['state'], ['pending', 'approved']) && ! $revision && LeaveType::findOrFail($v['leave_type_id'])->is_paid) {
                throw ValidationException::withMessages(['leave_type_id' => 'Configure an applicable entitlement policy in Leave settings before requesting paid leave.']);
            }
            $managed = $revision !== null;
            $leave ??= new EmployeeLeave;
            $leave->fill($v + ['reviewed_by_user_id' => $actor->id, 'reviewed_at' => now(), 'balance_managed' => $managed])->save();
            if (in_array($leave->state, ['pending', 'approved']) && $managed) {
                $balances->allocate($leave, $actor);
            }
            app(PayrollFreshnessService::class)->invalidate($user->id, $date);

            return $leave->fresh();
        }, 3);
    }

    public function assertPeriodOpen(User $user, Carbon $date): void
    {
        if (SalarySlip::where('user_id', $user->id)->whereDate('period_start', '<=', $date)->whereDate('period_end', '>=', $date)->whereIn('status', ['reviewed', 'approved', 'paid'])->exists()) {
            throw ValidationException::withMessages(['leave_date' => 'This payroll period is closed for leave changes. Use a reviewed payroll adjustment.']);
        }
    }
}
