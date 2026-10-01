<?php

namespace App\Services\Attendance;

use App\Models\AttendanceApproval;
use App\Models\AttendanceDay;
use App\Models\AttendanceOccurrence;
use App\Models\ShiftRuleRevision;
use App\Models\AttendanceLog;
use App\Models\HolidayOccurrence;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class AttendanceCalculationService
{
    public function __construct(private readonly ShiftAssignmentResolver $assignments) {}

    public function calculate(User $user, Carbon $workDate): AttendanceDay
    {
        return DB::transaction(function () use ($user, $workDate) {
            $set = $this->assignments->resolve($user, $workDate);
            $day = AttendanceDay::where('user_id', $user->id)->whereDate('work_date', $workDate->toDateString())->first()
                ?? new AttendanceDay(['user_id' => $user->id, 'work_date' => $workDate->toDateString()]);
            if ($day->exists) {
                $day->revisions()->firstOrCreate(['revision' => $day->calculation_revision], ['snapshot' => ['day' => $day->toArray(), 'occurrences' => $day->occurrences()->with(['punches', 'schedule', 'ruleRevision'])->get()->toArray(), 'approvals' => $day->approvals()->get()->toArray()], 'had_approved_attendance' => $day->approvals()->where('type', 'attendance')->where('state', 'approved')->exists(), 'had_approved_overtime' => $day->approvals()->where('type', 'overtime')->where('state', 'approved')->exists(), 'superseded_at' => now()]);
            }
            $revision = ($day->exists ? $day->calculation_revision : 0) + 1;
            $day->fill(['status' => $set ? 'pending' : 'no_shift', 'candidate_worked_minutes' => 0, 'approved_worked_minutes' => 0, 'candidate_overtime_minutes' => 0, 'approved_overtime_minutes' => 0, 'calculation_revision' => $revision, 'calculated_at' => now(), 'stale_at' => null])->save();
            $day->approvals()->where('state', 'pending')->update(['state' => 'superseded', 'superseded_at' => now()]);
            $day->occurrences()->delete();
            if ($this->isHoliday($user, $workDate)) {
                $day->update(['status' => 'holiday']);

                return $day;
            }
            if (! $set) {
                return $day;
            }
            $slots = $set->slots->values();
            $used = [];
            $intervals = [];
            $occurrences = [];
            foreach ($slots as $index => $slot) {
                $rule = $slot->schedule?->activeRuleRevision($workDate);
                if (! $rule || ! $rule->weekdays()->where('weekday', $workDate->dayOfWeek)->exists()) {
                    continue;
                }
                $start = Carbon::parse($workDate->toDateString().' '.$rule->start_time);
                $end = Carbon::parse($workDate->toDateString().' '.$rule->end_time)->addDays((int) $rule->end_day_offset);
                $windowStart = $start->copy()->subMinutes($rule->earliest_arrival_minutes);
                $cutoff = $end->copy()->addMinutes($rule->checkout_cutoff_minutes);
                $next = $slots->get($index + 1);
                if ($next) {
                    $nextRule = $next->schedule?->activeRuleRevision($workDate);
                    if ($nextRule) {
                        $nextOpen = Carbon::parse($workDate->toDateString().' '.$nextRule->start_time)->addDays((int) ($nextRule->start_time < $rule->start_time))->subMinutes($nextRule->earliest_arrival_minutes);
                        if ($nextOpen->lt($cutoff)) {
                            $cutoff = $nextOpen;
                        }
                    }
                }
                $punches = AttendanceLog::where('pin', $user->pin)->whereBetween('punched_at', [$windowStart, $cutoff])->when($used, fn ($q) => $q->whereNotIn('id', $used))->orderBy('punched_at')->get();
                [$in,$out,$workedIntervals] = $this->pair($punches, $rule->punch_method);
                $assumed = null;
                if ($in && ! $out && $rule->auto_checkout_enabled && now()->gte($cutoff)) {
                    $candidate = Carbon::parse($workDate->toDateString().' '.$rule->auto_checkout_time)->addDays((int) $rule->auto_checkout_day_offset);
                    if ($candidate->gt($in->punched_at) && $candidate->lte($cutoff)) {
                        $assumed = $candidate;
                        $workedIntervals = [[$in->punched_at, $candidate]];
                    }
                }
                $minutes = $this->unionMinutes($workedIntervals);
                $breakMinutes = $this->breakMinutes($rule, $workDate, $workedIntervals);
                $minutes = max(0, $minutes - $breakMinutes);
                foreach ($punches as $p) {
                    $used[] = $p->id;
                }
                $late = $in ? $this->wholeMinutes(max(0, $start->diffInMinutes($in->punched_at, false) - (int) $rule->arrival_grace_minutes)) : 0;
                $outAt = $out?->punched_at ?? $assumed;
                $early = $outAt ? $this->wholeMinutes(max(0, $outAt->diffInMinutes($end, false) - (int) $rule->departure_grace_minutes)) : 0;
                $occ = $day->occurrences()->create(['shift_assignment_slot_id' => $slot->id, 'schedule_id' => $slot->schedule_id, 'shift_rule_revision_id' => $rule->id, 'position' => $slot->position, 'window_starts_at' => $windowStart, 'window_ends_at' => $cutoff, 'first_in_at' => $in?->punched_at, 'last_out_at' => $out?->punched_at, 'assumed_out_at' => $assumed, 'candidate_worked_minutes' => $minutes, 'approved_worked_minutes' => $assumed ? 0 : $minutes, 'late_minutes' => $late, 'early_minutes' => $early, 'status' => $assumed ? 'pending_approval' : ($in && $outAt ? 'complete' : 'missing_punch'), 'exception_flags' => $assumed ? ['missing_checkout'] : (! $in || ! $outAt ? ['missing_punch'] : []), 'explanation' => ['gross_minutes' => $this->unionMinutes($workedIntervals), 'break_minutes' => $breakMinutes, 'punch_method' => $rule->punch_method]]);
                foreach ($punches as $p) {
                    $occ->punches()->create(['attendance_log_id' => $p->id, 'role' => $p->id === $in?->id ? 'in' : ($p->id === $out?->id ? 'out' : 'evidence')]);
                }
                if ($assumed) {
                    $this->approval($day, $occ, 'attendance', ['reason' => 'missing_checkout', 'assumed_out_at' => $assumed->toIso8601String(), 'minutes' => $minutes]);
                }
                $intervals = array_merge($intervals, $workedIntervals);
                $occurrences[] = [$occ, $rule, $end, $workedIntervals];
            }
            if (! $occurrences) {
                $day->update(['status' => 'off']);

                return $day;
            }
            $policy = $set->dailyPolicyRuleRevision?->schedule?->activeRuleRevision($workDate) ?? $occurrences[0][1];
            $total = $this->unionMinutes($intervals);
            $full = (int) $policy->full_day_minutes;
            $half = (int) $policy->half_day_minutes;
            $eligible = $policy->overtime_basis === 'after_shift_end' ? collect($occurrences)->sum(fn ($v) => $this->minutesAfter($v[3], $v[2])) : max(0, $total - $full);
            $ot = $eligible >= (int) $policy->overtime_minimum_minutes ? $eligible : 0;
            $day->update(['candidate_worked_minutes' => $total, 'approved_worked_minutes' => $day->occurrences()->sum('approved_worked_minutes'), 'candidate_overtime_minutes' => $ot, 'status' => self::dailyStatus($total, $half, $full), 'explanation' => ['assignment_set_id' => $set->id, 'daily_policy_rule_revision_id' => $policy->id]]);
            if ($ot > 0) {
                $this->approval($day, null, 'overtime', ['reason' => 'qualifying_overtime', 'minutes' => $ot, 'basis' => $policy->overtime_basis]);
            }

            return $day->fresh('occurrences');
        });
    }

    /** Update derived attendance only; device punches remain immutable. */
    public function reviseAssumedCheckout(AttendanceDay $day, AttendanceOccurrence $occurrence, Carbon $checkout): void
    {
        $rule = $occurrence->ruleRevision()->firstOrFail();
        $intervals = [[$occurrence->first_in_at, $checkout]];
        $breakMinutes = $this->breakMinutes($rule, $day->work_date, $intervals);
        $minutes = max(0, $this->unionMinutes($intervals) - $breakMinutes);
        $end = Carbon::parse($day->work_date->toDateString().' '.$rule->end_time)->addDays((int) $rule->end_day_offset);
        $occurrence->update([
            'last_out_at' => $checkout, 'assumed_out_at' => null,
            'candidate_worked_minutes' => $minutes, 'approved_worked_minutes' => $minutes,
            'early_minutes' => $this->wholeMinutes(max(0, $checkout->diffInMinutes($end, false) - (int) $rule->departure_grace_minutes)),
            'status' => 'corrected', 'exception_flags' => ['missing_checkout', 'hr_checkout'],
            'explanation' => array_merge($occurrence->explanation ?? [], ['checkout_source' => 'hr', 'gross_minutes' => $this->unionMinutes($intervals), 'break_minutes' => $breakMinutes]),
        ]);
        $occurrences = $day->occurrences()->with(['ruleRevision', 'punches.attendanceLog'])->get();
        $policy = ShiftRuleRevision::find($day->explanation['daily_policy_rule_revision_id'] ?? null) ?? $rule;
        $total = (int) $occurrences->sum('candidate_worked_minutes');
        $eligible = max(0, $total - (int) $policy->full_day_minutes);
        if ($policy->overtime_basis === 'after_shift_end') {
            $eligible = 0;
            foreach ($occurrences as $slot) {
                $slotRule = $slot->ruleRevision;
                if (! $slotRule) {
                    continue;
                }
                $slotEnd = Carbon::parse($day->work_date->toDateString().' '.$slotRule->end_time)->addDays((int) $slotRule->end_day_offset);
                if (($slot->explanation['checkout_source'] ?? null) === 'hr' || $slot->assumed_out_at) {
                    $worked = [[$slot->first_in_at, $slot->last_out_at ?? $slot->assumed_out_at]];
                } else {
                    [, , $worked] = $this->pair($slot->punches->pluck('attendanceLog')->filter()->sortBy('punched_at')->values(), $slotRule->punch_method);
                }
                $eligible += $this->minutesAfter($worked, $slotEnd);
            }
        }
        $day->update([
            'candidate_worked_minutes' => $total, 'approved_worked_minutes' => $occurrences->sum('approved_worked_minutes'),
            'candidate_overtime_minutes' => $eligible >= (int) $policy->overtime_minimum_minutes ? $eligible : 0,
            'status' => self::dailyStatus($total, (int) $policy->half_day_minutes, (int) $policy->full_day_minutes),
            'explanation' => array_merge($day->explanation ?? [], ['overtime_basis' => $policy->overtime_basis]),
        ]);
    }

    private function pair(Collection $p, string $method): array
    {
        if ($method === 'device') {
            $stack = null;
            $intervals = [];
            $first = null;
            $last = null;
            foreach ($p as $log) {
                if ((int) $log->status === 0) {
                    $stack = $log;
                    $first ??= $log;
                } elseif ((int) $log->status === 1 && $stack && $log->punched_at->gt($stack->punched_at)) {
                    $intervals[] = [$stack->punched_at, $log->punched_at];
                    $last = $log;
                    $stack = null;
                }
            }

            return [$first, $last, $intervals];
        }$in = $p->first();
        $out = $p->count() > 1 ? $p->last() : null;

        return [$in, $out, $in && $out ? [[$in->punched_at, $out->punched_at]] : []];
    }

    private function unionMinutes(array $intervals): int
    {
        $ranges = collect($intervals)->filter(fn ($v) => $v[0] && $v[1] && Carbon::parse($v[1])->gt($v[0]))->sortBy(fn ($v) => Carbon::parse($v[0])->timestamp)->values();
        $total = 0;
        $s = null;
        $e = null;
        foreach ($ranges as [$a,$b]) {
            $a = Carbon::parse($a);
            $b = Carbon::parse($b);
            if (! $s) {
                $s = $a;
                $e = $b;

                continue;
            }if ($a->lte($e)) {
                $e = $b->gt($e) ? $b : $e;
            } else {
                $total += $s->diffInMinutes($e);
                $s = $a;
                $e = $b;
            }
        }

        return $s ? $total + $s->diffInMinutes($e) : 0;
    }

    private function breakMinutes($rule, Carbon $date, array $intervals): int
    {
        $total = 0;
        foreach ($rule->breaks as $break) {
            if (! DB::table('shift_break_weekdays')->where('shift_break_id', $break->id)->where('weekday', $date->dayOfWeek)->exists()) {
                continue;
            }$start = Carbon::parse($date->toDateString().' '.$break->start_time)->addDays($break->day_offset);
            $end = $start->copy()->addMinutes($break->duration_minutes);
            foreach ($intervals as [$a,$b]) {
                $from = Carbon::parse($a)->max($start);
                $to = Carbon::parse($b)->min($end);
                if ($to->gt($from)) {
                    $total += $from->diffInMinutes($to);
                }
            }
        }

        return $total;
    }

    private function minutesAfter(array $intervals, Carbon $after): int
    {
        return collect($intervals)->sum(function ($v) use ($after) {
            $s = Carbon::parse($v[0])->max($after);
            $e = Carbon::parse($v[1]);

            return $e->gt($s) ? $s->diffInMinutes($e) : 0;
        });
    }

    private function wholeMinutes(float|int $minutes): int
    {
        return (int) floor($minutes);
    }

    private function approval(AttendanceDay $day, $occ, string $type, array $proposal): void
    {
        AttendanceApproval::create(['attendance_day_id' => $day->id, 'attendance_occurrence_id' => $occ?->id, 'type' => $type, 'state' => 'pending', 'calculation_revision' => $day->calculation_revision, 'proposal' => $proposal]);
    }

    public function isHoliday(User $user, Carbon $date): bool
    {
        return HolidayOccurrence::whereDate('holiday_date', $date)->whereHas('holiday', function ($q) use ($user) {
            $q->where('active', true)->where(function ($q) use ($user) {
                $q->whereDoesntHave('branches')->whereDoesntHave('departments')->whereDoesntHave('taskGroups')->orWhereHas('branches', fn ($x) => $x->whereKey($user->branch_id))->orWhereHas('departments', fn ($x) => $x->whereKey($user->department_id))->orWhereHas('taskGroups', fn ($x) => $x->whereIn('task_groups.id', $user->taskGroups()->pluck('task_groups.id')));
            });
        })->exists();
    }

    public static function dailyStatus(int $workedMinutes, int $halfDayMinutes = 120, int $fullDayMinutes = 480): string
    {
        return $workedMinutes >= $fullDayMinutes ? 'present' : ($workedMinutes >= $halfDayMinutes ? 'half_day' : 'absent');
    }
}
