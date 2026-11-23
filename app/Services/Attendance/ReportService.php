<?php

namespace App\Services\Attendance;

use App\Models\AttendanceDay;
use App\Models\User;
use App\Services\Support\TenantTimezone;
use Carbon\CarbonPeriod;

class ReportService
{
    public function generateReport(array $userIds, string $fromDate, string $toDate): array
    {
        $start = TenantTimezone::parse($fromDate)->startOfDay();
        $end = TenantTimezone::parse($toDate)->endOfDay();
        $period = CarbonPeriod::create($start, $end);
        $users = User::employees()->whereIn('id', $userIds)->get();
        $results = AttendanceDay::with(['occurrences.schedule', 'approvals'])->whereIn('user_id', $userIds)->whereDate('work_date', '>=', $start->toDateString())->whereDate('work_date', '<=', $end->toDateString())->get()->groupBy('user_id');
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
                    $daily[$key] = array_replace(AttendanceDayDisplay::forDay(null), ['status' => 'N', 'display' => '00:00', 'ot_minutes' => 0]);

                    continue;
                }
                $view = AttendanceDayDisplay::forDay($day);
                $code = match ($view['status']) {
                    'present' => 'P','half_day' => 'H','absent' => 'A','off' => 'O','holiday' => 'O','pending' => '?','no_shift' => 'N',default => '!'
                };
                $minutes = $view['minutes'];
                $approvedOt = $view['unapproved_checkout'] ? 0 : $day->approved_overtime_minutes;
                $total += $minutes;
                $ot += $approvedOt;
                $present += (int) ($view['status'] === 'present');
                $half += (int) ($view['status'] === 'half_day');
                $absent += (int) ($view['status'] === 'absent');
                $daily[$key] = array_replace($view, ['status' => $code, 'display' => $view['clock'], 'ot_minutes' => $approvedOt,
                    'late_minutes' => $day->occurrences->sum('late_minutes'), 'early_minutes' => $day->occurrences->sum('early_minutes'),
                    'exception' => $day->occurrences->contains(fn ($o) => ! empty($o->exception_flags))]);
            }
            $rows[] = ['user_name' => $user->name, 'user_pin' => $user->pin, 'daily' => $daily, 'total_minutes' => $total,
                'total_display' => AttendanceDayDisplay::hours($total)['clock'],
                'overtime_minutes' => $ot, 'overtime_display' => AttendanceDayDisplay::hours($ot)['clock'],
                'present' => $present, 'half_day' => $half, 'absent' => $absent];
        }

        return ['period' => collect($period)->map(fn ($d) => ['date' => $d->toDateString(), 'day' => $d->format('D'), 'month_day' => $d->format('M d')])->all(), 'data' => $rows];
    }
}
