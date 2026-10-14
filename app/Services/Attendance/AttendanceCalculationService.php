<?php

namespace App\Services\Attendance;

use App\Models\AttendanceApproval;
use App\Models\AttendanceDay;
use App\Models\AttendanceLog;
use App\Models\AttendanceOccurrence;
use App\Models\HolidayOccurrence;
use App\Models\ManualAttendanceEntry;
use App\Models\ShiftRuleRevision;
use App\Models\User;
use App\Services\Leave\CompensatoryLeaveService;
use App\Services\Payroll\PayrollFreshnessService;
use Carbon\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class AttendanceCalculationService
{
    public function __construct(private readonly ShiftAssignmentResolver $assignments) {}

    public function calculate(User $user, Carbon $workDate): AttendanceDay
    {
        return DB::transaction(function () use ($user, $workDate) {
            // Lock an existing parent before creating a day: this also protects
            // the first calculation, where there is no day row to lock yet.
            User::whereKey($user->id)->lockForUpdate()->firstOrFail();
            $set = $this->assignments->resolve($user, $workDate);
            $calendarKind = app(CompensatoryLeaveService::class)->calendarKind($user, $workDate);
            $manual = ManualAttendanceEntry::with(['schedule', 'ruleRevision'])->where('user_id', $user->id)->whereDate('work_date', $workDate)->orderBy('id')->get();
            $signature = $this->inputSignature($user, $workDate, $set);
            $day = AttendanceDay::where('user_id', $user->id)->whereDate('work_date', $workDate->toDateString())->lockForUpdate()->first()
                ?? new AttendanceDay(['user_id' => $user->id, 'work_date' => $workDate->toDateString()]);
            if ($day->exists && ($day->explanation['input_signature'] ?? null) === $signature) {
                $day->update(['stale_at' => null]);

                return $day->fresh('occurrences');
            }
            if ($day->exists && empty($day->explanation['input_signature']) && $day->approvals()->where('calculation_revision', $day->calculation_revision)->where('state', 'approved')->exists()) {
                throw ValidationException::withMessages(['attendance' => 'Legacy approved attendance has no input baseline. Review and migrate its decision before recalculating; the approved result was preserved.']);
            }
            app(PayrollFreshnessService::class)->invalidate($user->id, $workDate);
            if ($day->exists) {
                $day->revisions()->firstOrCreate(['revision' => $day->calculation_revision], ['snapshot' => ['day' => $day->toArray(), 'occurrences' => $day->occurrences()->with(['punches', 'schedule', 'ruleRevision'])->get()->toArray(), 'approvals' => $day->approvals()->get()->toArray()], 'had_approved_attendance' => $day->approvals()->where('type', 'attendance')->where('state', 'approved')->exists(), 'had_approved_overtime' => $day->approvals()->where('type', 'overtime')->where('state', 'approved')->exists(), 'superseded_at' => now()]);
            }
            $revision = ($day->exists ? $day->calculation_revision : 0) + 1;
            $day->fill(['status' => $set ? 'pending' : 'no_shift', 'candidate_worked_minutes' => 0, 'approved_worked_minutes' => 0, 'candidate_overtime_minutes' => 0, 'approved_overtime_minutes' => 0, 'calculation_revision' => $revision, 'calculated_at' => now(), 'stale_at' => null, 'explanation' => ['input_signature' => $signature, 'calendar_kind' => $calendarKind]])->save();
            $day->approvals()->where('state', 'pending')->update(['state' => 'superseded', 'superseded_at' => now()]);
            $day->occurrences()->delete();
            if ($calendarKind === 'holiday' && ! $set && $manual->isEmpty()) {
                $day->update(['status' => 'holiday']);

                return $day;
            }
            if (! $set && $manual->isEmpty()) {
                return $day;
            }
            $slots = $set?->slots->values() ?? collect();
            foreach ($manual->unique('schedule_id') as $entry) {
                if (! $slots->contains(fn ($slot) => $slot->schedule->id === $entry->schedule_id)) {
                    $slots->push((object) ['id' => null, 'schedule_id' => $entry->schedule_id, 'schedule' => $entry->schedule, 'position' => $slots->count() + 1]);
                }
            }
            $used = [];
            $intervals = [];
            $occurrences = [];
            foreach ($slots as $index => $slot) {
                $manualForShift = $manual->where('schedule_id', $slot->schedule->id);
                $rule = $manualForShift->first()?->ruleRevision ?? $slot->schedule?->activeRuleRevision($workDate);
                if (! $rule) {
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
                if ($manualForShift->isEmpty() && $punches->isEmpty() && ($calendarKind === 'holiday' || ! $rule->weekdays()->where('weekday', $workDate->dayOfWeek)->exists())) {
                    continue;
                }
                [$in,$out,$workedIntervals,$open] = $this->pair($punches, $rule->punch_method);
                foreach ($manualForShift as $entry) {
                    $workedIntervals[] = [$entry->in_at, $entry->out_at];
                    if (! $in || $entry->in_at->lt($in->punched_at)) {
                        $in = new AttendanceLog(['punched_at' => $entry->in_at]);
                    }
                    if (! $out || $entry->out_at->gt($out->punched_at)) {
                        $out = new AttendanceLog(['punched_at' => $entry->out_at]);
                    }
                }
                $assumed = null;
                if (($open || ($in && ! $out)) && $manualForShift->isEmpty() && $rule->auto_checkout_enabled && now()->gte($cutoff)) {
                    $candidate = Carbon::parse($workDate->toDateString().' '.$rule->auto_checkout_time)->addDays((int) $rule->auto_checkout_day_offset);
                    if ($candidate->gt(($open ?? $in)->punched_at) && $candidate->lte($cutoff)) {
                        $assumed = $candidate;
                        $workedIntervals[] = [($open ?? $in)->punched_at, $candidate];
                    }
                }
                $workedIntervals = $this->mergeIntervals($workedIntervals);
                $minutes = $this->unionMinutes($workedIntervals);
                $breakMinutes = $this->breakMinutes($rule, $workDate, $workedIntervals);
                $minutes = max(0, $minutes - $breakMinutes);
                foreach ($punches as $p) {
                    $used[] = $p->id;
                }
                $late = $in ? $this->wholeMinutes(max(0, $start->diffInMinutes($in->punched_at, false) - (int) $rule->arrival_grace_minutes)) : 0;
                $outAt = $assumed ?? $out?->punched_at;
                $early = $outAt ? $this->wholeMinutes(max(0, $outAt->diffInMinutes($end, false) - (int) $rule->departure_grace_minutes)) : 0;
                $occ = $day->occurrences()->create(['shift_assignment_slot_id' => $slot->id, 'schedule_id' => $slot->schedule_id, 'shift_rule_revision_id' => $rule->id, 'position' => $slot->position, 'window_starts_at' => $windowStart, 'window_ends_at' => $cutoff, 'first_in_at' => $in?->punched_at, 'last_out_at' => $out?->punched_at, 'assumed_out_at' => $assumed, 'candidate_worked_minutes' => $minutes, 'approved_worked_minutes' => ($assumed || $manualForShift->isNotEmpty()) ? 0 : $minutes, 'late_minutes' => $late, 'early_minutes' => $early, 'source' => $manualForShift->isEmpty() ? 'device' : ($punches->isEmpty() ? 'manual' : 'mixed'), 'status' => ($assumed || $manualForShift->isNotEmpty()) ? 'pending_approval' : ($in && $outAt ? 'complete' : 'missing_punch'), 'exception_flags' => $assumed ? ['missing_checkout'] : (! $in || ! $outAt ? ['missing_punch'] : []), 'explanation' => ['gross_minutes' => $this->unionMinutes($workedIntervals), 'break_minutes' => $breakMinutes, 'punch_method' => $rule->punch_method, 'worked_intervals' => collect($workedIntervals)->map(fn ($v) => [$v[0]->toIso8601String(), $v[1]->toIso8601String()])->all(), 'open_in_at' => $open?->punched_at?->toIso8601String()]]);
                foreach ($punches as $p) {
                    $occ->punches()->create(['attendance_log_id' => $p->id, 'role' => $p->id === $in?->id ? 'in' : ($p->id === $out?->id ? 'out' : 'evidence')]);
                }
                if ($manualForShift->isNotEmpty()) {
                    $this->approval($day, $occ, 'attendance', ['reason' => 'manual_attendance', 'entry_ids' => $manualForShift->pluck('id')->all(), 'minutes' => $minutes]);
                }
                if ($assumed) {
                    $this->approval($day, $occ, 'attendance', ['reason' => 'missing_checkout', 'assumed_out_at' => $assumed->toIso8601String(), 'minutes' => $minutes]);
                }
                $netIntervals = $this->withoutBreaks($rule, $workDate, $workedIntervals);
                $intervals = array_merge($intervals, $netIntervals);
                $occurrences[] = [$occ, $rule, $end, $netIntervals];
            }
            if (! $occurrences) {
                $day->update(['status' => $calendarKind === 'holiday' ? 'holiday' : 'off']);

                return $day;
            }
            $policy = $set?->dailyPolicyRuleRevision?->schedule?->activeRuleRevision($workDate) ?? $occurrences[0][1];
            $total = $this->unionMinutes($intervals);
            $full = (int) $policy->full_day_minutes;
            $half = (int) $policy->half_day_minutes;
            $eligible = $policy->overtime_basis === 'after_shift_end' ? collect($occurrences)->sum(fn ($v) => $this->minutesAfter($v[3], $v[2])) : max(0, $total - $full);
            $ot = $eligible >= (int) $policy->overtime_minimum_minutes ? $eligible : 0;
            $day->update(['source' => ($day->occurrences()->where('source', 'mixed')->exists() || ($day->occurrences()->where('source', 'manual')->exists() && $day->occurrences()->where('source', 'device')->exists())) ? 'mixed' : ($day->occurrences()->where('source', 'manual')->exists() ? 'manual' : 'device'), 'candidate_worked_minutes' => $total, 'approved_worked_minutes' => $day->occurrences()->sum('approved_worked_minutes'), 'candidate_overtime_minutes' => $ot, 'status' => self::dailyStatus($total, $half, $full), 'explanation' => ['input_signature' => $signature, 'calendar_kind' => $calendarKind, 'assignment_set_id' => $set?->id, 'daily_policy_rule_revision_id' => $policy->id]]);
            $this->allocateOvertime($day);
            $this->requestOvertime($day);

            return $day->fresh('occurrences');
        }, 3);
    }

    public function inputSignature(User $user, Carbon $date, $set): string
    {
        $rules = [];
        $windows = [];
        foreach ($set?->slots ?? [] as $slot) {
            $rule = $slot->schedule?->activeRuleRevision($date);
            if (! $rule) {
                continue;
            }
            $rule->load(['weekdays', 'breaks']);
            $cutoff = Carbon::parse($date->toDateString().' '.$rule->end_time)->addDays((int) $rule->end_day_offset)->addMinutes($rule->checkout_cutoff_minutes);
            // Include off-day/holiday device evidence in the revision fingerprint.
            $windows[] = [Carbon::parse($date->toDateString().' '.$rule->start_time)->subMinutes($rule->earliest_arrival_minutes), $cutoff];
            $rules[] = [$slot->id, $rule->toArray(), now()->gte($cutoff), DB::table('shift_break_weekdays')->whereIn('shift_break_id', $rule->breaks->pluck('id'))->orderBy('id')->get()->toArray()];
        }
        foreach (ManualAttendanceEntry::with('ruleRevision')->where('user_id', $user->id)->whereDate('work_date', $date)->get() as $entry) {
            $rule = $entry->ruleRevision;
            $windows[] = [Carbon::parse($date->toDateString().' '.$rule->start_time)->subMinutes($rule->earliest_arrival_minutes), Carbon::parse($date->toDateString().' '.$rule->end_time)->addDays($rule->end_day_offset)->addMinutes($rule->checkout_cutoff_minutes)];
        }
        $punches = $windows ? AttendanceLog::where('pin', $user->pin)->where(function ($query) use ($windows) {
            foreach ($windows as $window) {
                $query->orWhereBetween('punched_at', $window);
            }
        })->orderBy('id')->get(['id', 'punched_at', 'status'])->toArray() : [];
        $policy = $set?->dailyPolicyRuleRevision?->schedule?->activeRuleRevision($date);

        return hash('sha256', json_encode([$user->pin, $set?->id, $policy?->toArray(), $rules, $punches, ManualAttendanceEntry::where('user_id', $user->id)->whereDate('work_date', $date)->orderBy('id')->get()->toArray(), $this->isHoliday($user, $date)], JSON_THROW_ON_ERROR));
    }

    /** Update derived attendance only; device punches remain immutable. */
    public function reviseAssumedCheckout(AttendanceDay $day, AttendanceOccurrence $occurrence, Carbon $checkout, bool $replaceInterval = false): void
    {
        $rule = $occurrence->ruleRevision()->firstOrFail();
        $open = $occurrence->explanation['open_in_at'] ?? null;
        $intervals = ! $replaceInterval && $open ? array_map(fn ($v) => [Carbon::parse($v[0]), Carbon::parse($v[1])], $occurrence->explanation['worked_intervals'] ?? []) : [];
        if ($open && ! $replaceInterval) {
            $intervals = array_values(array_filter($intervals, fn ($v) => $v[0]->lt(Carbon::parse($open))));
        }
        $intervals[] = [(! $replaceInterval && $open) ? Carbon::parse($open) : $occurrence->first_in_at, $checkout];
        if ($replaceInterval && count($occurrence->explanation['worked_intervals'] ?? []) > 1) {
            $intervals = array_map(fn ($v) => [Carbon::parse($v[0]), Carbon::parse($v[1])], $occurrence->explanation['worked_intervals']);
            $intervals[0][0] = $occurrence->first_in_at;
            $intervals[count($intervals) - 1][1] = $checkout;
            foreach ($intervals as $interval) {
                if ($interval[1]->lte($interval[0])) {
                    throw ValidationException::withMessages(['correction' => 'Corrected times would invert an existing work session.']);
                }
            }
        }
        $breakMinutes = $this->breakMinutes($rule, $day->work_date, $intervals);
        $minutes = max(0, $this->unionMinutes($intervals) - $breakMinutes);
        $end = Carbon::parse($day->work_date->toDateString().' '.$rule->end_time)->addDays((int) $rule->end_day_offset);
        $occurrence->update([
            'last_out_at' => $checkout, 'assumed_out_at' => null,
            'candidate_worked_minutes' => $minutes, 'approved_worked_minutes' => $minutes,
            'early_minutes' => $this->wholeMinutes(max(0, $checkout->diffInMinutes($end, false) - (int) $rule->departure_grace_minutes)),
            'status' => 'corrected', 'exception_flags' => ['missing_checkout', 'hr_checkout'],
            'explanation' => array_merge($occurrence->explanation ?? [], ['checkout_source' => 'hr', 'gross_minutes' => $this->unionMinutes($intervals), 'break_minutes' => $breakMinutes, 'worked_intervals' => collect($intervals)->map(fn ($v) => [$v[0]->toIso8601String(), $v[1]->toIso8601String()])->all(), 'open_in_at' => null]),
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
                    $worked = array_map(fn ($v) => [Carbon::parse($v[0]), Carbon::parse($v[1])], $slot->explanation['worked_intervals'] ?? [[$slot->first_in_at->toIso8601String(), ($slot->last_out_at ?? $slot->assumed_out_at)->toIso8601String()]]);
                } else {
                    [, , $worked] = $this->pair($slot->punches->pluck('attendanceLog')->filter()->sortBy('punched_at')->values(), $slotRule->punch_method);
                }
                $eligible += $this->minutesAfter($this->withoutBreaks($slotRule, $day->work_date, $worked), $slotEnd);
            }
        }
        $day->update([
            'candidate_worked_minutes' => $total, 'approved_worked_minutes' => $occurrences->sum('approved_worked_minutes'),
            'candidate_overtime_minutes' => $eligible >= (int) $policy->overtime_minimum_minutes ? $eligible : 0,
            'status' => self::dailyStatus($total, (int) $policy->half_day_minutes, (int) $policy->full_day_minutes),
            'explanation' => array_merge($day->explanation ?? [], ['overtime_basis' => $policy->overtime_basis]),
        ]);
        $this->allocateOvertime($day);
    }

    public function allocateOvertime(AttendanceDay $day): void
    {
        $slots = $day->occurrences()->with('ruleRevision')->orderByDesc('position')->get();
        $policy = ShiftRuleRevision::find($day->explanation['daily_policy_rule_revision_id'] ?? null) ?? $slots->first()?->ruleRevision;
        if (! $policy) {
            return;
        }
        $available = [];
        $all = [];
        $approved = [];
        foreach ($slots as $slot) {
            $rule = $slot->ruleRevision;
            $intervals = array_map(fn ($v) => [Carbon::parse($v[0]), Carbon::parse($v[1])], $slot->explanation['worked_intervals'] ?? []);
            $net = $this->withoutBreaks($rule, $day->work_date, $intervals);
            $all = array_merge($all, $net);
            if ($slot->approved_worked_minutes > 0) {
                $approved = array_merge($approved, $net);
            }
            $end = Carbon::parse($day->work_date->toDateString().' '.$rule->end_time)->addDays($rule->end_day_offset);
            $available[$slot->id] = $policy->overtime_basis === 'after_shift_end' ? $this->minutesAfter($net, $end) : $slot->candidate_worked_minutes;
        }
        $eligible = $policy->overtime_basis === 'after_shift_end' ? array_sum($available) : max(0, $day->candidate_worked_minutes - $policy->full_day_minutes);
        $pool = $eligible >= $policy->overtime_minimum_minutes ? $eligible : 0;
        $legacy = $day->approvals()->where('type', 'overtime')->where('state', 'approved')->whereNull('attendance_occurrence_id')->exists() ? max(0, $day->approved_overtime_minutes - $slots->sum('approved_overtime_minutes')) : 0;
        $protected = $slots->sum('approved_overtime_minutes') + $legacy;
        if ($protected > $pool) {
            throw ValidationException::withMessages(['correction' => 'The corrected attendance cannot support already approved overtime. Review that overtime decision first.']);
        }
        $remaining = $pool - $protected;
        foreach ($slots as $slot) {
            $minutes = $slot->approved_overtime_minutes > 0 ? $slot->approved_overtime_minutes : min($remaining, $available[$slot->id]);
            if ($slot->approved_overtime_minutes === 0) {
                $remaining -= $minutes;
            }
            $slot->update(['candidate_overtime_minutes' => $minutes]);
        }
        $day->update(['approved_worked_minutes' => $this->unionMinutes($approved), 'candidate_overtime_minutes' => $pool, 'approved_overtime_minutes' => $protected]);
    }

    public function requestOvertime(AttendanceDay $day): void
    {
        foreach ($day->occurrences()->where('candidate_overtime_minutes', '>', 0)->where('approved_overtime_minutes', 0)->get() as $slot) {
            $this->approval($day, $slot, 'overtime', ['reason' => 'qualifying_overtime', 'minutes' => $slot->candidate_overtime_minutes, 'basis' => $day->explanation['overtime_basis'] ?? 'after_required_hours']);
        }
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

            return [$first, $last, $intervals, $stack];
        }$in = $p->first();
        $out = $p->count() > 1 ? $p->last() : null;

        return [$in, $out, $in && $out ? [[$in->punched_at, $out->punched_at]] : [], $in && ! $out ? $in : null];
    }

    private function mergeIntervals(array $intervals): array
    {
        $result = [];
        foreach (collect($intervals)->sortBy(fn ($v) => Carbon::parse($v[0])->timestamp) as [$start,$end]) {
            $start = Carbon::parse($start);
            $end = Carbon::parse($end);
            if ($end->lte($start)) {
                continue;
            }
            $last = count($result) - 1;
            if ($last >= 0 && $start->lte($result[$last][1])) {
                $result[$last][1] = $result[$last][1]->max($end);
            } else {
                $result[] = [$start, $end];
            }
        }

        return $result;
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

    private function withoutBreaks($rule, Carbon $date, array $intervals): array
    {
        foreach ($rule->breaks as $break) {
            if (! DB::table('shift_break_weekdays')->where('shift_break_id', $break->id)->where('weekday', $date->dayOfWeek)->exists()) {
                continue;
            }
            $start = Carbon::parse($date->toDateString().' '.$break->start_time)->addDays($break->day_offset);
            $end = $start->copy()->addMinutes($break->duration_minutes);
            $remaining = [];
            foreach ($intervals as [$a, $b]) {
                $a = Carbon::parse($a);
                $b = Carbon::parse($b);
                if ($b->lte($start) || $a->gte($end)) {
                    $remaining[] = [$a, $b];

                    continue;
                }
                if ($a->lt($start)) {
                    $remaining[] = [$a, $start];
                }
                if ($b->gt($end)) {
                    $remaining[] = [$end, $b];
                }
            }
            $intervals = $remaining;
        }

        return $intervals;
    }

    private function breakMinutes($rule, Carbon $date, array $intervals): int
    {
        return $this->unionMinutes($intervals) - $this->unionMinutes($this->withoutBreaks($rule, $date, $intervals));
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
