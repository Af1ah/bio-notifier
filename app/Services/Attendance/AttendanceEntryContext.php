<?php

namespace App\Services\Attendance;

use App\Models\AttendanceDay;
use App\Models\AttendanceLog;
use App\Models\User;
use App\Services\Support\TenantTimezone;
use Illuminate\Validation\ValidationException;

final class AttendanceEntryContext
{
    public function candidates(User $user, string $date): array
    {
        $day = AttendanceDay::where('user_id', $user->id)->whereDate('work_date', $date)->first();
        $occurrences = $day?->occurrences()->with(['schedule', 'ruleRevision'])->orderBy('position')->get();
        if ($occurrences?->isNotEmpty()) {
            return $occurrences->filter(fn ($occurrence) => $occurrence->ruleRevision !== null)->map(fn ($occurrence) => [
                'occurrence' => $occurrence, 'schedule_id' => $occurrence->schedule_id,
                'name' => $occurrence->schedule?->name ?? 'Shift', 'rule' => $occurrence->ruleRevision,
                'start' => $occurrence->window_starts_at, 'end' => $occurrence->window_ends_at,
            ])->values()->all();
        }
        $slots = app(ShiftAssignmentResolver::class)->resolve($user, TenantTimezone::parse($date))?->slots ?? collect();
        $candidates = [];
        foreach ($slots->values() as $index => $slot) {
            $rule = $slot->schedule?->activeRuleRevision(TenantTimezone::parse($date));
            if (! $rule) {
                continue;
            }
            $end = TenantTimezone::parse($date.' '.$rule->end_time)->addDays($this->overnight($rule) ? 1 : 0)->addMinutes($rule->checkout_cutoff_minutes);
            $nextRule = $slots->values()->get($index + 1)?->schedule?->activeRuleRevision(TenantTimezone::parse($date));
            if ($nextRule) {
                $nextStart = TenantTimezone::parse($date.' '.$nextRule->start_time)->addDays((int) ($nextRule->start_time < $rule->start_time))->subMinutes($nextRule->earliest_arrival_minutes);
                $end = $end->min($nextStart);
            }
            $candidates[] = ['occurrence' => null, 'schedule_id' => $slot->schedule_id, 'name' => $slot->schedule->name, 'rule' => $rule,
                'start' => TenantTimezone::parse($date.' '.$rule->start_time)->subMinutes($rule->earliest_arrival_minutes), 'end' => $end];
        }

        return $candidates;
    }

    public function match(User $user, string $date, string $inTime, string $outTime): array
    {
        $candidates = $this->candidates($user, $date);
        if ($candidates === []) {
            throw ValidationException::withMessages(['user_id' => 'Assign a shift or organisation default for this date first.']);
        }
        $matches = [];
        foreach ($candidates as $candidate) {
            $in = TenantTimezone::parse($date.' '.$inTime);
            $out = TenantTimezone::parse($date.' '.$outTime);
            if ($this->overnight($candidate['rule'])) {
                if ($in->lt($candidate['start'])) {
                    $in->addDay();
                }
                if ($out->lte($in)) {
                    $out->addDay();
                }
            }
            if ($out->gt($in) && $in->gte($candidate['start']) && $out->lte($candidate['end'])) {
                $matches[] = $candidate + ['in' => $in, 'out' => $out];
            }
        }
        if (count($matches) !== 1) {
            throw ValidationException::withMessages(['in_time' => $matches === []
                ? 'Enter check-in and checkout within the assigned shift. Only night shifts can end the next day.'
                : 'These times overlap more than one shift. Enter times within a single shift.']);
        }

        return $matches[0];
    }

    public function initial(User $user, string $date): array
    {
        $candidates = $this->candidates($user, $date);
        if ($candidates === []) {
            return ['in_time' => null, 'out_time' => null];
        }
        $logs = AttendanceLog::where('pin', $user->pin)->where(function ($query) use ($candidates): void {
            foreach ($candidates as $candidate) {
                $query->orWhereBetween('punched_at', [$candidate['start'], $candidate['end']]);
            }
        })->orderBy('punched_at')->orderBy('id')->get();
        foreach ($candidates as $candidate) {
            $occurrence = $candidate['occurrence'];
            [$in, $out] = app(AttendanceCalculationService::class)->pair(
                $logs->filter(fn ($log) => $log->punched_at->betweenIncluded($candidate['start'], $candidate['end']))->values(),
                $candidate['rule']->punch_method,
            );
            $inAt = $occurrence?->first_in_at ?? $in?->punched_at;
            $outAt = $occurrence?->last_out_at ?? $occurrence?->assumed_out_at ?? $out?->punched_at;
            if (! $outAt && $candidate['rule']->punch_method === 'device') {
                $outAt = $logs->last(fn ($log) => $log->attendance_status === 1 && $log->punched_at->betweenIncluded($candidate['start'], $candidate['end'])
                    && (! $inAt || $log->punched_at->gt($inAt)))?->punched_at;
            }
            if ($inAt || $outAt) {
                return ['in_time' => $inAt?->format('H:i'), 'out_time' => $outAt?->format('H:i')];
            }
        }

        return ['in_time' => null, 'out_time' => null];
    }

    private function overnight($rule): bool
    {
        return (int) $rule->end_day_offset > 0 || $rule->end_time <= $rule->start_time;
    }
}
