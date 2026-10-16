<?php

namespace App\Services\Payroll;

use App\Models\AttendanceApproval;
use App\Models\AttendanceDay;
use App\Models\EmployeeLeave;
use App\Models\SalarySlip;
use App\Models\User;
use App\Services\Attendance\ShiftAssignmentResolver;
use App\Services\Leave\CompensatoryLeaveService;
use App\Services\Leave\LeaveBalanceService;
use App\Services\Leave\LeavePolicyService;
use Carbon\Carbon;
use Carbon\CarbonPeriod;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class SalarySlipService
{
    /**
     * Return the reasons a salary slip cannot be generated without changing data.
     *
     * @return array<int, string>
     */
    public function readinessIssues(User $user, Carbon $period): array
    {
        $start = $period->copy()->startOfMonth();
        $end = $period->copy()->endOfMonth();
        [$start,$end] = app(ManualPayrollReviewService::class)->bounds($user, $period);
        $profile = app(PayrollProfileHistoryService::class)->forDate($user, $start);
        $issues = [];
        if ($end->lt($start)) {
            return ['The employee has no eligible employment dates in this month.'];
        }

        if (! $profile?->payroll_enabled) {
            return ['Payroll is not enabled.'];
        }

        if ($profile->effective_from?->gt($end)) {
            $issues[] = 'Salary details are not effective in this payroll month.';
        }

        $days = AttendanceDay::query()
            ->where('user_id', $user->id)
            ->whereDate('work_date', '>=', $start->toDateString())
            ->whereDate('work_date', '<=', $end->toDateString())
            ->get();

        if ($days->isEmpty()) {
            $issues[] = 'Calculated attendance is missing for this payroll month.';

            return $issues;
        }
        if ($missing = $this->missingCoverage($user, $start, $end, $days)) {
            $issues[] = 'Attendance is incomplete for payroll dates: '.implode(', ', array_slice($missing, 0, 5)).'. Calculate the full payroll period, including off days, or configure effective shift assignments.';
        }

        if ($days->contains(fn (AttendanceDay $day) => $day->stale_at !== null)) {
            $issues[] = 'Attendance contains stale results that must be recalculated.';
        }

        if (EmployeeLeave::where('user_id', $user->id)->whereDate('leave_date', '>=', $start)->whereDate('leave_date', '<=', $end)->where('state', 'pending')->exists()) {
            $issues[] = 'Leave requests are still pending.';
        }
        $leaves = EmployeeLeave::query()->where('user_id', $user->id)
            ->whereDate('leave_date', '>=', $start->toDateString())->whereDate('leave_date', '<=', $end->toDateString())
            ->where('state', 'approved')->get();
        try {
            $this->attendanceUnits($days, $leaves);
            foreach ($leaves as $leave) {
                app(LeaveBalanceService::class)->payrollCoverage($leave);
            }
            app(CompensatoryLeaveService::class)->payrollPayment($user, $start, $end);
        } catch (ValidationException $exception) {
            $issues[] = collect($exception->errors())->flatten()->first();
        }

        if (AttendanceApproval::query()
            ->whereHas('attendanceDay', fn ($query) => $query->where('user_id', $user->id)->whereBetween('work_date', [$start, $end]))
            ->where('type', 'attendance')
            ->where('state', 'pending')
            ->exists()) {
            $issues[] = 'Attendance approvals are still pending.';
        }

        if (! $days->contains(fn (AttendanceDay $day) => in_array($day->status, ['present', 'half_day', 'absent', 'pending'], true)) && ! (($profile->pay_basis ?: 'monthly') === 'monthly' && $days->every(fn ($day) => in_array($day->status, ['off', 'holiday'], true)))) {
            $hasApprovedLeave = EmployeeLeave::query()
                ->where('user_id', $user->id)
                ->whereDate('leave_date', '>=', $start->toDateString())->whereDate('leave_date', '<=', $end->toDateString())
                ->where('state', 'approved')
                ->exists();

            if (! $hasApprovedLeave) {
                $issues[] = 'No scheduled attendance or approved leave days are available.';
            }
        }

        $existing = SalarySlip::query()
            ->where('user_id', $user->id)
            ->whereDate('period_start', $period->copy()->startOfMonth())
            ->whereDate('period_end', $period->copy()->endOfMonth())
            ->first(['status']);

        if ($existing && $existing->status !== 'draft') {
            $issues[] = "The existing salary slip is {$existing->status} and cannot be recalculated.";
        }

        if (app(ManualPayrollReviewService::class)->required($user, $period) && ! app(ManualPayrollReviewService::class)->approved($user, $period)) {
            $issues[] = 'Manual payroll period requires HR review of the complete eligible calendar.';
        }

        return $issues;
    }

    public function generate(User $user, Carbon $period): SalarySlip
    {
        $start = $period->copy()->startOfMonth();
        $end = $period->copy()->endOfMonth();

        $periodStart = $start->copy();
        $periodEnd = $end->copy();

        return DB::transaction(function () use ($user, $start, $end, $periodStart, $periodEnd) {
            $user = User::whereKey($user->id)->lockForUpdate()->firstOrFail();
            $user->payrollProfile()->lockForUpdate()->first();
            $profile = app(PayrollProfileHistoryService::class)->forDate($user, $periodStart);
            $user->setRelation('payrollProfile', $profile);
            [$start,$end] = app(ManualPayrollReviewService::class)->bounds($user, $periodStart);
            if ($end->lt($start)) {
                throw ValidationException::withMessages(['payroll' => 'The employee has no eligible employment dates in this month.']);
            }
            if (! $profile?->payroll_enabled) {
                throw ValidationException::withMessages(['payroll' => "Payroll is not enabled for {$user->name}."]);
            }
            if ($profile->effective_from?->gt($end)) {
                throw ValidationException::withMessages(['payroll' => "{$user->name}'s salary is not effective in this period."]);
            }

            $days = AttendanceDay::query()
                ->where('user_id', $user->id)
                ->whereDate('work_date', '>=', $start->toDateString())
                ->whereDate('work_date', '<=', $end->toDateString())
                ->with('approvals')
                ->orderBy('work_date')
                ->get();

            if ($days->isEmpty()) {
                throw ValidationException::withMessages(['attendance' => "No calculated attendance is available for {$user->name} in {$start->format('F Y')}."]);
            }
            if ($missing = $this->missingCoverage($user, $start, $end, $days)) {
                throw ValidationException::withMessages(['attendance' => 'Attendance is incomplete for payroll dates: '.implode(', ', array_slice($missing, 0, 5)).'. Calculate the full payroll period, including off days, or configure effective shift assignments.']);
            }
            if ($days->contains(fn (AttendanceDay $day) => $day->stale_at !== null)) {
                throw ValidationException::withMessages(['attendance' => "{$user->name} has stale attendance that must be recalculated first."]);
            }
            if (AttendanceApproval::query()
                ->whereHas('attendanceDay', fn ($query) => $query->where('user_id', $user->id)->whereBetween('work_date', [$start, $end]))
                ->where('type', 'attendance')
                ->where('state', 'pending')
                ->exists()) {
                throw ValidationException::withMessages(['attendance' => "{$user->name} has pending attendance approvals."]);
            }

            if (app(ManualPayrollReviewService::class)->required($user, $periodStart) && ! app(ManualPayrollReviewService::class)->approved($user, $periodStart)) {
                throw ValidationException::withMessages(['review' => 'Manual payroll period requires HR review of the complete eligible calendar.']);
            }

            if (EmployeeLeave::where('user_id', $user->id)->whereDate('leave_date', '>=', $start)->whereDate('leave_date', '<=', $end)->where('state', 'pending')->exists()) {
                throw ValidationException::withMessages(['leave' => 'Resolve pending leave requests before payroll.']);
            }
            $leaves = EmployeeLeave::query()
                ->where('user_id', $user->id)
                ->whereDate('leave_date', '>=', $start->toDateString())->whereDate('leave_date', '<=', $end->toDateString())
                ->where('state', 'approved')
                ->with('leaveType')
                ->orderBy('leave_date')
                ->get();
            $offDates = $days->whereIn('status', ['off', 'holiday'])->pluck('work_date')->map->toDateString()->flip();
            $leaves = $leaves->reject(fn (EmployeeLeave $leave) => $offDates->has($leave->leave_date->toDateString()));
            $leaveDates = $leaves->keyBy(fn (EmployeeLeave $leave) => $leave->leave_date->toDateString());
            $scheduledStatuses = ['present', 'half_day', 'absent', 'pending'];
            $scheduledDates = $days->whereIn('status', $scheduledStatuses)->pluck('work_date')->map->toDateString()
                ->merge($leaveDates->keys())->unique()->values();
            $scheduledDays = $scheduledDates->count();
            if ($scheduledDays === 0 && ! (($profile->pay_basis ?: 'monthly') === 'monthly' && $days->every(fn ($day) => in_array($day->status, ['off', 'holiday'], true)))) {
                throw ValidationException::withMessages(['attendance' => "No scheduled attendance days are available for {$user->name}."]);
            }

            $attendance = $this->attendanceUnits($days, $leaves);
            $fullDays = $attendance['full_days'];
            $halfDays = $attendance['half_days'];
            $absentDays = $attendance['absent_days'];
            $overtimeMinutes = (int) $days->sum('approved_overtime_minutes');
            $payBasis = $profile->pay_basis ?: 'monthly';
            $baseRate = (int) $profile->basic_salary_minor;
            $calendarDivisor = $periodStart->daysInMonth;
            $eligibleDays = (int) $start->diffInDays($end) + 1;
            $scheduledDayRate = $this->divideMoney($payBasis === 'monthly' ? $this->divideMoney($baseRate * $eligibleDays, $calendarDivisor) : $baseRate, max(1, $scheduledDays));
            $calendarDayRate = $this->divideMoney($baseRate, $calendarDivisor);
            $approvedWorkedMinutes = (int) $days->sum('approved_worked_minutes');
            $regularWorkedMinutes = max(0, $approvedWorkedMinutes - $overtimeMinutes);

            $basic = match ($payBasis) {
                'daily' => ($fullDays * $baseRate) + $this->halfUnitsMoney($baseRate, $halfDays),
                'hourly' => $this->minutesMoney($baseRate, $regularWorkedMinutes),
                default => $this->divideMoney($baseRate * $eligibleDays, $calendarDivisor),
            };
            $attendanceDeduction = $payBasis === 'monthly'
                ? $this->divideMoney($baseRate * $attendance['absent_half_units'], $calendarDivisor * 2)
                : 0;

            [$paidLeaveUnits, $unpaidLeaveUnits, $leaveDeduction, $leaveSnapshot] = $this->leaveTotals(
                $user,
                $leaves,
                $start,
                $scheduledDayRate,
                $baseRate,
                $calendarDivisor,
                $payBasis === 'monthly',
            );
            $overtimeRate = (int) $profile->overtime_hourly_rate_minor;
            if ($payBasis === 'hourly' && $overtimeRate === 0) {
                $overtimeRate = $baseRate;
            }
            $overtimeResult = app(OvertimePayService::class)->calculate($days, $profile);
            $overtimePay = $overtimeResult['amount_minor'];
            $housing = $payBasis === 'monthly' ? $this->divideMoney((int) $profile->housing_allowance_minor * $eligibleDays, $calendarDivisor) : 0;
            $transport = $payBasis === 'monthly' ? $this->divideMoney((int) $profile->transport_allowance_minor * $eligibleDays, $calendarDivisor) : 0;
            $other = $payBasis === 'monthly' ? $this->divideMoney((int) $profile->other_allowance_minor * $eligibleDays, $calendarDivisor) : 0;
            $fixedDeduction = $payBasis === 'monthly' ? $this->divideMoney((int) $profile->fixed_deduction_minor * $eligibleDays, $calendarDivisor) : 0;
            $compensatoryPayment = app(CompensatoryLeaveService::class)->payrollPayment($user, $start, $end);
            $paidLeavePay = 0;
            if ($payBasis === 'daily') {
                $paidLeavePay = $this->halfUnitsMoney($baseRate, $paidLeaveUnits);
            }
            if ($payBasis === 'hourly') {
                foreach ($leaveSnapshot as $leaveResult) {
                    if (! $leaveResult['paid_half_units']) {
                        continue;
                    }
                    $set = app(ShiftAssignmentResolver::class)->resolve($user, Carbon::parse($leaveResult['date']));
                    $rule = $set?->dailyPolicyRuleRevision?->schedule?->activeRuleRevision(Carbon::parse($leaveResult['date']));
                    if (! $rule) {
                        throw ValidationException::withMessages(['leave' => 'Hourly paid leave requires an effective shift daily-hours rule.']);
                    }
                    $paidLeavePay += $this->divideMoney($baseRate * (int) $rule->full_day_minutes * $leaveResult['paid_half_units'], 120);
                }
            }
            $other += $compensatoryPayment + $paidLeavePay;
            $gross = $basic + $housing + $transport + $other + $overtimePay;
            $totalDeduction = $fixedDeduction + $attendanceDeduction + $leaveDeduction;

            $existing = SalarySlip::query()
                ->where('user_id', $user->id)
                ->whereDate('period_start', $periodStart)
                ->whereDate('period_end', $periodEnd)
                ->lockForUpdate()
                ->first();
            if ($existing && $existing->status !== 'draft') {
                throw ValidationException::withMessages(['salary_slip' => "{$user->name}'s slip is {$existing->status} and cannot be recalculated."]);
            }

            $payload = [
                'user_id' => $user->id,
                'period_start' => $periodStart->toDateString(),
                'period_end' => $periodEnd->toDateString(),
                'status' => 'draft',
                'designation' => $user->group,
                'currency' => $profile->currency,
                'basic_salary_minor' => $basic,
                'housing_allowance_minor' => $housing,
                'transport_allowance_minor' => $transport,
                'other_allowance_minor' => $other,
                'overtime_pay_minor' => $overtimePay,
                'gross_pay_minor' => $gross,
                'attendance_deduction_minor' => $attendanceDeduction,
                'leave_deduction_minor' => $leaveDeduction,
                'fixed_deduction_minor' => $fixedDeduction,
                'total_deduction_minor' => $totalDeduction,
                'net_pay_minor' => max(0, $gross - $totalDeduction),
                'scheduled_days' => $scheduledDays,
                'full_days' => $fullDays,
                'half_days' => $halfDays,
                'absent_days' => $absentDays,
                'paid_leave_half_units' => $paidLeaveUnits,
                'unpaid_leave_half_units' => $unpaidLeaveUnits,
                'approved_overtime_minutes' => $overtimeMinutes,
                'calculation_snapshot' => [
                    'profile_id' => $profile->id,
                    'profile_updated_at' => $profile->updated_at?->toIso8601String(),
                    'attendance_days' => $days->map(fn ($day) => ['id' => $day->id, 'date' => $day->work_date->toDateString(), 'status' => $day->status, 'revision' => $day->calculation_revision, 'approved_overtime_minutes' => $day->approved_overtime_minutes])->all(),
                    'leaves' => $leaveSnapshot,
                    'compensatory_payment_minor' => $compensatoryPayment,
                    'paid_leave_pay_minor' => $paidLeavePay,
                    'overtime_breakdown' => $overtimeResult['rows'],
                    'leave_balance_evidence' => app(LeaveBalanceService::class)->payrollEvidence($user, $start, $end),
                    'attendance_absent_half_units' => $attendance['absent_half_units'],
                    'leave_other_half_absent_days' => $attendance['leave_other_half_absent_days'],
                    'day_units' => $attendance['days'],
                    'pay_basis' => $payBasis,
                    'base_rate_minor' => $baseRate,
                    'scheduled_day_rate_minor' => $scheduledDayRate,
                    'calendar_day_rate_minor' => $calendarDayRate,
                    'calendar_day_divisor' => $calendarDivisor, 'proration_policy' => 'actual_calendar_days', 'eligible_days' => $eligibleDays, 'eligible_start' => $start->toDateString(), 'eligible_end' => $end->toDateString(),
                    'approved_worked_minutes' => $approvedWorkedMinutes,
                ],
                'calculation_version' => ($existing?->calculation_version ?? 0) + 1,
                'calculated_at' => now(),
            ];

            return $existing ? tap($existing)->update($payload) : SalarySlip::create($payload);
        });
    }

    private function missingCoverage(User $user, Carbon $start, Carbon $end, Collection $days, ?bool $monthly = null): array
    {
        $monthly ??= ($user->payrollProfile?->pay_basis ?: 'monthly') === 'monthly';
        $covered = $days->pluck('work_date')->map->toDateString()->flip();
        $missing = [];
        $resolver = app(ShiftAssignmentResolver::class);
        $effective = $user->payrollProfile?->effective_from;
        foreach (CarbonPeriod::create($start, $end) as $date) {
            if ($covered->has($date->toDateString()) || ($effective && $date->lt($effective))) {
                continue;
            }
            $set = $resolver->resolve($user, $date);
            if (! $set && $monthly) {
                $missing[] = $date->toDateString();

                continue;
            }
            if ($set && $set->slots->contains(function ($slot) use ($date) {
                return $slot->schedule?->activeRuleRevision($date)?->weekdays()->where('weekday', $date->dayOfWeek)->exists();
            })) {
                $missing[] = $date->toDateString();
            }
        }

        return $missing;
    }

    /** Reconcile leave and attendance without charging the same half twice. */
    private function attendanceUnits(Collection $days, Collection $leaves): array
    {
        $byDate = $days->keyBy(fn ($day) => $day->work_date->toDateString());
        $leaveByDate = $leaves->keyBy(fn ($leave) => $leave->leave_date->toDateString());
        $result = ['full_days' => 0, 'half_days' => 0, 'absent_days' => 0,
            'absent_half_units' => 0, 'leave_other_half_absent_days' => 0, 'days' => []];
        foreach ($byDate->keys()->merge($leaveByDate->keys())->unique() as $date) {
            $day = $byDate->get($date);
            $leave = $leaveByDate->get($date);
            if (in_array($day?->status, ['off', 'holiday'], true)) {
                continue;
            }
            if (! $leave && ! in_array($day?->status, ['present', 'half_day', 'absent', 'pending'], true)) {
                continue;
            }
            if ($leave?->duration === 'half' && ! $day) {
                throw ValidationException::withMessages(['attendance' => "Calculate attendance for approved leave on {$date} before generating payroll."]);
            }
            $worked = match ($day?->status) {
                'present' => 2,
                'half_day' => 1,
                default => 0,
            };
            $leaveUnits = $leave?->half_units ?? 0;
            if ($leave && ($worked + $leaveUnits > 2 || ($leaveUnits === 2 && (int) $day?->approved_worked_minutes > 0))) {
                throw ValidationException::withMessages(['attendance' => "Approved leave conflicts with worked attendance on {$date}. Correct the leave or attendance before generating payroll."]);
            }
            $absent = 2 - $worked - $leaveUnits;
            $result['full_days'] += (int) ($worked === 2);
            $result['half_days'] += (int) ($worked === 1);
            $result['absent_days'] += (int) ($absent === 2);
            $result['absent_half_units'] += $absent;
            $result['leave_other_half_absent_days'] += (int) ($leaveUnits === 1 && $absent === 1);
            $result['days'][] = ['date' => $date, 'worked_half_units' => $worked,
                'leave_half_units' => $leaveUnits, 'absent_half_units' => $absent];
        }

        return $result;
    }

    public function transition(SalarySlip $slip, User $actor, string $target): SalarySlip
    {
        if ((int) $actor->privilege !== 14) {
            throw ValidationException::withMessages(['authorization' => 'Only an administrator can change a salary slip status.']);
        }

        return DB::transaction(function () use ($slip, $actor, $target) {
            $locked = SalarySlip::lockForUpdate()->findOrFail($slip->id);
            if ($locked->calculation_snapshot['needs_recalculation'] ?? false) {
                throw ValidationException::withMessages(['attendance' => 'Holiday changes affected this slip. Recalculate or resolve the payroll adjustment before proceeding.']);
            }
            $allowed = ['draft' => 'reviewed', 'reviewed' => 'approved', 'approved' => 'paid'];
            if (($allowed[$locked->status] ?? null) !== $target) {
                throw ValidationException::withMessages(['status' => "A {$locked->status} slip cannot be changed to {$target}."]);
            }
            [$eligibleStart,$eligibleEnd] = app(ManualPayrollReviewService::class)->bounds($locked->user, $locked->period_start);
            if (($locked->calculation_snapshot['pay_basis'] ?? 'monthly') === 'monthly') {
                [$eligibleStart,$eligibleEnd] = app(ManualPayrollReviewService::class)->bounds($locked->user, $locked->period_start);
                $days = AttendanceDay::where('user_id', $locked->user_id)
                    ->whereDate('work_date', '>=', $eligibleStart->toDateString())
                    ->whereDate('work_date', '<=', $eligibleEnd->toDateString())->get();
                if ($this->missingCoverage($locked->user, $eligibleStart, $eligibleEnd, $days, true)) {
                    throw ValidationException::withMessages(['attendance' => 'Monthly payroll attendance is incomplete. Resolve the missing dates before reviewing, approving or marking this slip paid.']);
                }
            }
            if (app(ManualPayrollReviewService::class)->required($locked->user, $locked->period_start) && ! app(ManualPayrollReviewService::class)->approved($locked->user, $locked->period_start)) {
                throw ValidationException::withMessages(['review' => 'Manual payroll period requires a current HR review before this status change.']);
            }
            if (isset($locked->calculation_snapshot['eligible_start']) && ($locked->calculation_snapshot['eligible_start'] !== $eligibleStart->toDateString() || $locked->calculation_snapshot['eligible_end'] !== $eligibleEnd->toDateString())) {
                throw ValidationException::withMessages(['payroll' => 'Employment eligibility changed. Recalculate draft payroll or resolve an adjustment first.']);
            }
            app(CompensatoryLeaveService::class)->payrollPayment($locked->user, $eligibleStart, $eligibleEnd);
            $currentLeaveEvidence = app(LeaveBalanceService::class)->payrollEvidence($locked->user, $eligibleStart, $eligibleEnd);
            if (isset($locked->calculation_snapshot['leave_balance_evidence']) && $locked->calculation_snapshot['leave_balance_evidence'] !== $currentLeaveEvidence) {
                throw ValidationException::withMessages(['leave' => 'Leave allocation or compensatory reward changed. Recalculate payroll before proceeding.']);
            }
            $field = match ($target) {
                'reviewed' => ['reviewed_by_user_id' => $actor->id, 'reviewed_at' => now()],
                'approved' => ['approved_by_user_id' => $actor->id, 'approved_at' => now()],
                'paid' => ['paid_by_user_id' => $actor->id, 'paid_at' => now()],
            };
            $locked->update(['status' => $target, ...$field]);

            return $locked->fresh();
        });
    }

    private function leaveTotals(User $user, Collection $leaves, Carbon $start, int $dayRate, int $basicSalary, int $calendarDivisor, bool $applyLeavePolicy): array
    {
        $paid = 0;
        $unpaid = 0;
        $deduction = 0;
        $snapshot = [];
        $usedBefore = EmployeeLeave::query()
            ->where('user_id', $user->id)
            ->where('state', 'approved')
            ->whereDate('leave_date', '>=', $start->copy()->startOfYear())
            ->whereDate('leave_date', '<', $start)
            ->get()
            ->groupBy('leave_type_id')
            ->map->sum('half_units');

        foreach ($leaves as $leave) {
            $type = $leave->leaveType;
            $units = $leave->half_units;
            $used = (int) ($usedBefore[$type->id] ?? 0);
            $allocatedCoverage = app(LeaveBalanceService::class)->payrollCoverage($leave);
            $covered = $allocatedCoverage !== null ? ($type->is_paid ? min($units, $allocatedCoverage) : 0) : ($applyLeavePolicy && $type->is_paid ? max(0, min($units, (int) $type->annual_allowance_half_units - $used)) : 0);
            $deductible = $units - $covered;
            $paid += $covered;
            $unpaid += $deductible;
            $amount = $applyLeavePolicy ? $leave->deduction_override_minor : 0;
            if ($amount === null && $applyLeavePolicy) {
                $method = $type->is_paid ? null : $type->deduction_method;
                $fixed = (int) $type->deduction_amount_minor;
                if ($type->is_paid && $deductible > 0) {
                    $rules = app(LeavePolicyService::class)->resolve($user, $type->id, $leave->leave_date)?->rules;
                    if (! $rules || ! $leave->balance_managed) {
                        throw ValidationException::withMessages(['leave' => 'Reconcile legacy uncovered paid-category leave using an unpaid category before recalculating payroll.']);
                    }
                    $method = $rules['unpaid_excess_deduction_method'] ?? null;
                    $fixed = (int) ($rules['unpaid_excess_deduction_minor'] ?? 0);
                    if (! in_array($method, ['salary_day_rate', 'basic_salary_31_day_rate', 'fixed_amount']) || ($method === 'fixed_amount' && $fixed <= 0)) {
                        throw ValidationException::withMessages(['leave' => 'Configure an unpaid-excess deduction in the applicable leave policy before payroll.']);
                    }
                }
                $amount = match ($method) {
                    'fixed_amount' => $this->halfUnitsMoney($fixed, $deductible),
                    'salary_day_rate' => $this->halfUnitsMoney($dayRate, $deductible),
                    // Keep the stored option key compatible with existing leave policies.
                    'basic_salary_31_day_rate' => $this->divideMoney($basicSalary * $deductible, $calendarDivisor * 2),
                    default => 0,
                };
            }
            $deduction += (int) $amount;
            $usedBefore[$type->id] = $used + $units;
            $snapshot[] = [
                'id' => $leave->id,
                'date' => $leave->leave_date->toDateString(),
                'type' => $type->name,
                'duration' => $leave->duration,
                'paid_half_units' => $covered,
                'balance_managed' => (bool) $leave->balance_managed,
                'deducted_half_units' => $deductible,
                'deduction_minor' => (int) $amount,
            ];
        }

        return [$paid, $unpaid, $deduction, $snapshot];
    }

    private function halfUnitsMoney(int $fullDayAmount, int $halfUnits): int
    {
        return intdiv(($fullDayAmount * $halfUnits) + 1, 2);
    }

    private function divideMoney(int $amount, int $divisor): int
    {
        return intdiv($amount + intdiv($divisor, 2), $divisor);
    }

    private function minutesMoney(int $hourlyRate, int $minutes): int
    {
        return intdiv(($minutes * $hourlyRate) + 30, 60);
    }
}
