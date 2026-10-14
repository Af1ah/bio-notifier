<?php

namespace App\Services\Leave;

use App\Models\AttendanceDay;
use App\Models\CompensatoryAward;
use App\Models\LeaveCreditLot;
use App\Models\User;
use App\Services\Attendance\AttendanceCalculationService;
use App\Services\Attendance\ShiftAssignmentResolver;
use App\Services\Payroll\PayrollFreshnessService;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class CompensatoryLeaveService
{
    public function evidence(AttendanceDay $day): array
    {
        $day = $day->fresh(['occurrences']);
        $calendar = $day->explanation['calendar_kind'] ?? $this->calendarKind($day->user, $day->work_date);

        return ['day_id' => $day->id, 'date' => $day->work_date->toDateString(), 'revision' => $day->calculation_revision,
            'calendar_kind' => $calendar, 'status' => $day->status, 'approved_minutes' => $day->approved_worked_minutes,
            'approved_overtime_minutes' => $day->approved_overtime_minutes, 'stale' => $day->stale_at?->toIso8601String(),
            'input_signature' => $day->explanation['input_signature'] ?? null,
            'occurrences' => $day->occurrences->map(fn ($o) => ['id' => $o->id, 'rule_id' => $o->shift_rule_revision_id, 'minutes' => $o->approved_worked_minutes, 'status' => $o->status, 'intervals' => $o->explanation['worked_intervals'] ?? []])->all()];
    }

    public function signature(AttendanceDay $day): string
    {
        return hash('sha256', json_encode($this->evidence($day), JSON_THROW_ON_ERROR));
    }

    public function calendarKind(User $user, Carbon $date): string
    {
        if (app(AttendanceCalculationService::class)->isHoliday($user, $date)) {
            return 'holiday';
        }
        $set = app(ShiftAssignmentResolver::class)->resolve($user, $date);
        if (! $set) {
            return 'unassigned';
        }

        return $set->slots->contains(fn ($slot) => $slot->schedule?->activeRuleRevision($date)?->weekdays()->where('weekday', $date->dayOfWeek)->exists()) ? 'working' : 'off';
    }

    public function award(AttendanceDay $day, int $typeId, string $reward, string $reason, string $expectedSignature, User $actor): CompensatoryAward
    {
        abort_unless((int) $actor->privilege === 14, 403);
        if (! in_array($reward, ['leave', 'payment', 'both']) || trim($reason) === '') {
            throw ValidationException::withMessages(['reason' => 'Choose a reward and provide an approval reason.']);
        }

        return DB::transaction(function () use ($day, $typeId, $reward, $reason, $expectedSignature, $actor) {
            $user = User::lockForUpdate()->findOrFail($day->user_id);
            $day = AttendanceDay::lockForUpdate()->findOrFail($day->id);
            app(EmployeeLeaveService::class)->assertPeriodOpen($user, $day->work_date);
            if ($existing = CompensatoryAward::where('user_id', $user->id)->whereDate('work_date', $day->work_date)->first()) {
                if ($existing->state === 'approved' && $existing->reward === $reward && hash_equals($existing->evidence_signature, $expectedSignature)) {
                    return $existing;
                }
                if ($existing->state !== 'reversed') {
                    throw ValidationException::withMessages(['reward' => 'This date already has a reward. Review or reverse the existing award first.']);
                }
            }
            $revision = app(LeavePolicyService::class)->resolve($user, $typeId, $day->work_date);
            $r = $revision?->rules;
            if (! $r || ! $r['enabled'] || ! $r['comp_enabled']) {
                throw ValidationException::withMessages(['leave_type_id' => 'No active compensatory policy applies to this employee and date.']);
            }
            $evidence = $this->evidence($day);
            $signature = $this->signature($day);
            if (! hash_equals($signature, $expectedSignature)) {
                throw ValidationException::withMessages(['reward' => 'Attendance changed during review. Reload the evidence.']);
            }
            if ($day->stale_at || $day->approvals()->where('calculation_revision', $day->calculation_revision)->where('state', 'pending')->exists() || ! $day->occurrences()->exists()) {
                throw ValidationException::withMessages(['reward' => 'Resolve stale attendance and pending attendance/overtime decisions first.']);
            }
            if ($day->work_date->gt(Carbon::today(LeaveSettingsService::timezone())) || $day->occurrences()->where('last_out_at', '>', now())->exists()) {
                throw ValidationException::withMessages(['reward' => 'Complete actual work before awarding a holiday/off-day reward.']);
            }
            $kind = $evidence['calendar_kind'];
            if (! (($kind === 'holiday' && $r['comp_holiday']) || ($kind === 'off' && $r['comp_off']))) {
                throw ValidationException::withMessages(['reward' => 'Work date is not an eligible holiday or rostered off day.']);
            }
            if ($r['comp_reward'] !== 'choice' && $r['comp_reward'] !== $reward) {
                throw ValidationException::withMessages(['reward' => 'Reward does not match the tenant policy.']);
            }
            if ($reward === 'both' && $r['comp_reward'] !== 'both') {
                throw ValidationException::withMessages(['reward' => 'This policy does not permit both rewards.']);
            }
            if ($day->approved_overtime_minutes > 0 && ! ($r['comp_allow_overtime'] ?? false)) {
                throw ValidationException::withMessages(['reward' => 'Overtime is already approved. This policy does not permit an additional holiday/off-day reward.']);
            }
            $units = $day->approved_worked_minutes >= $r['comp_full_minutes'] ? 2 : ($day->approved_worked_minutes >= $r['comp_half_minutes'] ? 1 : 0);
            if (! $units) {
                throw ValidationException::withMessages(['reward' => 'Approved net work does not meet the half-day credit threshold.']);
            }
            if (in_array($reward, ['payment', 'both']) && $user->payrollProfile()->value('currency') !== $r['comp_payment_currency']) {
                throw ValidationException::withMessages(['reward' => 'Employee payroll currency must match the compensatory payment policy currency.']);
            }
            $payment = in_array($reward, ['payment', 'both']) ? intdiv((int) $r['comp_payment_full_minor'] * $units + 1, 2) : 0;
            if (in_array($reward, ['payment', 'both']) && ! $payment) {
                throw ValidationException::withMessages(['reward' => 'Configure a positive holiday/off-day payment amount in the tenant policy.']);
            }
            $payload = ['user_id' => $user->id, 'work_date' => $day->work_date, 'attendance_day_id' => $day->id,
                'leave_policy_revision_id' => $revision->id, 'reward' => $reward, 'state' => 'approved', 'half_units' => $units,
                'payment_minor' => $payment, 'evidence_signature' => $signature, 'evidence' => $evidence + ['policy_rules' => $r], 'reason' => $reason, 'reviewed_by_user_id' => $actor->id];
            if ($existing) {
                $payload['evidence']['previous_award'] = $existing->toArray();
                $payload['evidence']['decision_number'] = ($existing->evidence['decision_number'] ?? 1) + 1;
                $existing->update($payload + ['leave_credit_lot_id' => null]);
                $award = $existing;
            } else {
                $award = CompensatoryAward::create($payload);
            }
            if (in_array($reward, ['leave', 'both'])) {
                $available = $day->work_date->copy()->addDays($r['comp_same_day'] ? 0 : 1);
                $lot = LeaveCreditLot::create(['user_id' => $user->id, 'leave_type_id' => $typeId, 'leave_policy_revision_id' => $revision->id, 'granted_half_units' => $units,
                    'available_on' => $available, 'expires_on' => $available->copy()->addDays($r['comp_expiry_days']), 'source_kind' => 'compensatory',
                    'source_key' => 'award:'.$award->id.':'.($award->evidence['decision_number'] ?? 1), 'evidence' => ['award_id' => $award->id, 'attendance_signature' => $signature]]);
                app(LeaveBalanceService::class)->entry($lot, 'grant', $units, $available, $lot->source_key, $reason, $actor);
                $award->update(['leave_credit_lot_id' => $lot->id]);
            }
            app(PayrollFreshnessService::class)->invalidate($user->id, $day->work_date);

            return $award;
        }, 3);
    }

    public function assertCurrent(CompensatoryAward $award): void
    {
        if ($award->state !== 'approved' || ! hash_equals($award->evidence_signature, $this->signature($award->day))) {
            throw ValidationException::withMessages(['reward' => 'Compensatory reward evidence changed. HR must review the award and any consumed leave before payroll or further use.']);
        }
    }

    public function reverse(CompensatoryAward $award, User $actor, string $reason): void
    {
        abort_unless((int) $actor->privilege === 14, 403);
        if (trim($reason) === '') {
            throw ValidationException::withMessages(['reason' => 'Provide a reversal reason.']);
        }
        DB::transaction(function () use ($award, $actor, $reason) {
            $user = User::lockForUpdate()->findOrFail($award->user_id);
            $award = CompensatoryAward::lockForUpdate()->findOrFail($award->id);
            app(EmployeeLeaveService::class)->assertPeriodOpen($user, $award->work_date);
            if ($award->state === 'reversed') {
                return;
            }
            if ($award->leave_credit_lot_id) {
                if (DB::table('leave_allocations')->where('leave_credit_lot_id', $award->leave_credit_lot_id)->whereIn('state', ['reserved', 'consumed'])->exists()) {
                    throw ValidationException::withMessages(['reason' => 'Award credits are already reserved or consumed. Cancel/reconcile those leave records before reversing the award.']);
                }
                $lot = LeaveCreditLot::findOrFail($award->leave_credit_lot_id);
                app(LeaveBalanceService::class)->entry($lot, 'reversal', -(int) $lot->granted_half_units, Carbon::today(), 'reverse-award:'.$award->leave_credit_lot_id, $reason, $actor);
            }
            $award->update(['state' => 'reversed', 'evidence' => $award->evidence + ['reversal_reason' => $reason, 'reversed_by' => $actor->id, 'reversed_at' => now()->toIso8601String()]]);
            app(PayrollFreshnessService::class)->invalidate($user->id, $award->work_date);
        }, 3);
    }

    public function payrollPayment(User $user, Carbon $start, Carbon $end): int
    {
        $awards = CompensatoryAward::where('user_id', $user->id)->whereDate('work_date', '>=', $start)->whereDate('work_date', '<=', $end)->where('state', '!=', 'reversed')->get();
        foreach ($awards as $award) {
            $this->assertCurrent($award);
        }

        return (int) $awards->sum('payment_minor');
    }
}
