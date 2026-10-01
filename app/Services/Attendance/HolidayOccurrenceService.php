<?php

namespace App\Services\Attendance;

use App\Models\Holiday;
use Carbon\Carbon;
use Carbon\CarbonPeriod;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class HolidayOccurrenceService
{
    public function sync(Holiday $holiday): void
    {
        $oldDates = $holiday->occurrences()->pluck('holiday_date')->all();
        DB::transaction(function () use ($holiday) {
            $holiday->occurrences()->delete();
            if (! $holiday->active) {
                return;
            }
            if ($holiday->recurrence === 'none') {
                foreach (CarbonPeriod::create($holiday->starts_on, $holiday->ends_on) as $date) {
                    $holiday->occurrences()->create(['holiday_date' => $date->toDateString()]);
                }

                return;
            }
            if ($holiday->recurrence === 'monthly_weekday') {
                $this->syncMonthlyWeekdays($holiday);

                return;
            }
            if ($holiday->recurrence !== 'yearly') {
                throw ValidationException::withMessages(['recurrence' => 'Select a supported holiday recurrence.']);
            }
            $first = (int) ($holiday->start_year ?: $holiday->starts_on->year);
            $last = (int) ($holiday->end_year ?: $first + 10);
            for ($year = $first; $year <= $last; $year++) {
                if ($holiday->starts_on->format('m-d') === '02-29' && ! Carbon::create($year)->isLeapYear()) {
                    continue;
                }
                $start = Carbon::createFromFormat('Y-m-d', $year.'-'.$holiday->starts_on->format('m-d'));
                $days = $holiday->starts_on->diffInDays($holiday->ends_on);
                foreach (CarbonPeriod::create($start, $start->copy()->addDays($days)) as $date) {
                    $holiday->occurrences()->firstOrCreate(['holiday_date' => $date->toDateString()]);
                }
            }
        });
        $dates = array_merge($oldDates, $holiday->occurrences()->pluck('holiday_date')->all());
        // Filament saves coverage relationships before committing its action transaction.
        DB::afterCommit(fn () => app(HolidayAttendanceService::class)->reconcile($dates));
    }

    private function syncMonthlyWeekdays(Holiday $holiday): void
    {
        $weekday = $holiday->monthly_weekday;
        if ($weekday === null || $weekday < 0 || $weekday > 6) {
            throw ValidationException::withMessages(['monthly_weekday' => 'Select the weekday to repeat.']);
        }

        $weeks = match ($holiday->monthly_pattern) {
            'even' => [2, 4],
            'odd' => [1, 3, 5],
            'custom' => array_values(array_unique(array_map('intval', $holiday->monthly_weeks ?? []))),
            default => [],
        };
        $weeks = array_values(array_filter($weeks, fn (int $week) => $week >= 1 && $week <= 5));
        sort($weeks);
        if (! $weeks) {
            throw ValidationException::withMessages(['monthly_weeks' => 'Select at least one week of the month.']);
        }

        $month = $holiday->starts_on->copy()->startOfMonth();
        $lastMonth = $holiday->ends_on->copy()->startOfMonth();
        while ($month->lte($lastMonth)) {
            $firstOffset = ($weekday - $month->dayOfWeek + 7) % 7;
            foreach ($weeks as $week) {
                $date = $month->copy()->addDays($firstOffset + (($week - 1) * 7));
                if ($date->month !== $month->month || $date->lt($holiday->starts_on) || $date->gt($holiday->ends_on)) {
                    continue;
                }
                $holiday->occurrences()->firstOrCreate(['holiday_date' => $date->toDateString()]);
            }
            $month->addMonthNoOverflow();
        }
    }
}
