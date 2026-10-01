<?php

namespace App\Services\Attendance;

use App\Models\AttendanceDay;
use App\Models\User;
use Carbon\Carbon;
use Carbon\CarbonPeriod;

class ReportService
{
    public function generateReport(array $userIds, string $fromDate, string $toDate): array
    {
        $start = Carbon::parse($fromDate)->startOfDay();
        $end = Carbon::parse($toDate)->endOfDay();
        $period = CarbonPeriod::create($start, $end);
        $users = User::whereIn('id', $userIds)->get();
        $results = AttendanceDay::with('occurrences.schedule')->whereIn('user_id', $userIds)->whereDate('work_date', '>=', $start->toDateString())->whereDate('work_date', '<=', $end->toDateString())->get()->groupBy('user_id');
        $rows = [];
        foreach ($users as $user) {
            $daily = [];
            $total = 0;
            $ot = 0;
            $present = 0;
            $half = 0;
            $absent = 0;
            foreach ($period as $date) {
                $key = $date->toDateString();
                $day = $results->get($user->id, collect())->first(fn ($r) => $r->work_date->toDateString() === $key);
                if (! $day) {
                    $daily[$key] = ['status' => 'N', 'display' => 'Not calculated', 'minutes' => 0, 'ot_minutes' => 0];

                    continue;
                }
                $code = match ($day->status) {
                    'present' => 'P','half_day' => 'H','absent' => 'A','off' => 'O','holiday' => 'O','pending' => '?','no_shift' => 'N',default => '!'
                };
                $minutes = $day->approved_worked_minutes ?: $day->candidate_worked_minutes;
                $approvedOt = $day->approved_overtime_minutes;
                $total += $minutes;
                $ot += $approvedOt;
                $present += (int) ($day->status === 'present');
                $half += (int) ($day->status === 'half_day');
                $absent += (int) ($day->status === 'absent');
                $daily[$key] = ['status' => $code, 'display' => sprintf('%dh %dm', intdiv($minutes, 60), $minutes % 60), 'minutes' => $minutes, 'ot_minutes' => $approvedOt, 'late_minutes' => $day->occurrences->sum('late_minutes'), 'early_minutes' => $day->occurrences->sum('early_minutes'), 'exception' => $day->occurrences->contains(fn ($o) => ! empty($o->exception_flags))];
            }
            $rows[] = ['user_name' => $user->name, 'user_pin' => $user->pin, 'daily' => $daily, 'total_minutes' => $total, 'total_display' => sprintf('%dh %dm', intdiv($total, 60), $total % 60), 'overtime_minutes' => $ot, 'overtime_display' => sprintf('%dh %dm', intdiv($ot, 60), $ot % 60), 'present' => $present, 'half_day' => $half, 'absent' => $absent];
        }

        return ['period' => collect($period)->map(fn ($d) => ['date' => $d->toDateString(), 'day' => $d->format('D'), 'month_day' => $d->format('M d')])->all(), 'data' => $rows];
    }
}
