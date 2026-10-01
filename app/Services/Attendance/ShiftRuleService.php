<?php

namespace App\Services\Attendance;

use App\Models\Schedule;
use App\Models\ShiftRuleRevision;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class ShiftRuleService
{
    public function sync(Schedule $schedule): ShiftRuleRevision
    {
        $rules = $schedule->rules ?? [];
        $half = (int) ($rules['half_day_minutes'] ?? 120);
        $full = (int) ($rules['full_day_minutes'] ?? 480);
        if (empty($rules['weekdays'])) {
            throw ValidationException::withMessages(['rules.weekdays' => 'Select at least one working day.']);
        }
        if ($half >= $full) {
            throw ValidationException::withMessages(['rules.half_day_minutes' => 'Half-day minimum must be below full-day minimum.']);
        }
        if (($rules['auto_checkout_enabled'] ?? false) && blank($rules['auto_checkout_time'] ?? null)) {
            throw ValidationException::withMessages(['rules.auto_checkout_time' => 'Enter an assumed checkout time.']);
        }
        $start = $this->minute($rules['start_time'] ?? '09:30');
        $end = $this->minute($rules['end_time'] ?? '18:30') + ((int) (bool) ($rules['end_day_offset'] ?? false) * 1440);
        if ($end <= $start) {
            throw ValidationException::withMessages(['rules.end_time' => 'Closing time must be after start time. Enable next day for overnight shifts.']);
        }
        $breakRanges = [];
        foreach ($rules['breaks'] ?? [] as $index => $break) {
            $from = $this->minute($break['start_time']) + ((int) ($break['day_offset'] ?? 0) * 1440);
            $to = $from + (int) $break['duration_minutes'];
            if ($from < $start || $to > $end) {
                throw ValidationException::withMessages(["rules.breaks.$index.start_time" => 'Break must be inside the shift.']);
            }foreach ($breakRanges as [$a,$b]) {
                if ($from < $b && $to > $a) {
                    throw ValidationException::withMessages(["rules.breaks.$index.start_time" => 'Breaks cannot overlap.']);
                }
            }$breakRanges[] = [$from, $to];
        }
        $snapshot = Arr::sortRecursive($rules);
        $latest = $schedule->ruleRevisions()->latest('revision')->first();
        if ($latest && $latest->snapshot === $snapshot && $latest->effective_from?->toDateString() === $schedule->valid_from?->toDateString()) {
            return $latest;
        }

        return DB::transaction(function () use ($schedule, $rules, $snapshot, $latest) {
            if ($latest && $latest->effective_to === null) {
                $latest->update(['effective_to' => $schedule->valid_from->copy()->subDay()]);
            }
            $revision = $schedule->ruleRevisions()->create([
                'revision' => ($latest?->revision ?? 0) + 1, 'effective_from' => $schedule->valid_from, 'effective_to' => $schedule->valid_to,
                'start_time' => $rules['start_time'] ?? '09:30', 'end_time' => $rules['end_time'] ?? '18:30', 'end_day_offset' => (int) (bool) ($rules['end_day_offset'] ?? false),
                'arrival_grace_minutes' => $rules['arrival_grace_minutes'] ?? 15, 'departure_grace_minutes' => $rules['departure_grace_minutes'] ?? 15, 'earliest_arrival_minutes' => $rules['earliest_arrival_minutes'] ?? 60,
                'half_day_minutes' => $rules['half_day_minutes'] ?? 120, 'full_day_minutes' => $rules['full_day_minutes'] ?? 480, 'punch_method' => $rules['punch_method'] ?? 'first_last',
                'overtime_basis' => $rules['overtime_basis'] ?? 'after_required_hours', 'overtime_minimum_minutes' => $rules['overtime_minimum_minutes'] ?? 30,
                'auto_checkout_enabled' => $rules['auto_checkout_enabled'] ?? false, 'auto_checkout_time' => $rules['auto_checkout_time'] ?? null, 'auto_checkout_day_offset' => (int) (bool) ($rules['auto_checkout_day_offset'] ?? false),
                'checkout_cutoff_minutes' => $rules['checkout_cutoff_minutes'] ?? 180, 'snapshot' => $snapshot,
            ]);
            foreach ($rules['weekdays'] ?? [1, 2, 3, 4, 5, 6] as $weekday) {
                $revision->weekdays()->create(['weekday' => $weekday]);
            }
            foreach ($rules['breaks'] ?? [] as $break) {
                $created = $revision->breaks()->create(['name' => $break['name'], 'start_time' => $break['start_time'], 'duration_minutes' => $break['duration_minutes'], 'day_offset' => $break['day_offset'] ?? 0]);
                foreach ($break['weekdays'] ?? [] as $weekday) {
                    DB::table('shift_break_weekdays')->insert(['shift_break_id' => $created->id, 'weekday' => $weekday]);
                }
            }

            return $revision;
        });
    }

    private function minute(string $time): int
    {
        [$hour,$minute] = array_map('intval', explode(':', $time));

        return $hour * 60 + $minute;
    }
}
