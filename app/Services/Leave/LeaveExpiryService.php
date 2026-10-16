<?php

namespace App\Services\Leave;

use Carbon\Carbon;

class LeaveExpiryService
{
    public function beforeNextReset(Carbon $available, array $dates): Carbon
    {
        $candidates = [];
        foreach ($dates as $row) {
            $date = Carbon::parse($row['date']);
            foreach ([$available->year, $available->year + 1] as $year) {
                $reset = Carbon::create($year, $date->month, 1)->startOfDay();
                $reset->day(min($date->day, $reset->daysInMonth));
                if ($reset->gt($available->copy()->startOfDay())) {
                    $candidates[] = $reset;
                }
            }
        }

        return collect($candidates)->sortBy(fn ($d) => $d->timestamp)->first()->copy()->subDay();
    }
}
