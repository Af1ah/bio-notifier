<?php

namespace App\Filament\Employee\Widgets;

use App\Filament\Employee\Pages\Attendance;
use App\Filament\Employee\Pages\Calendar;
use App\Filament\Employee\Pages\LeaveRequests;
use App\Models\AttendanceApproval;
use App\Models\AttendanceDay;
use App\Models\EmployeeLeave;
use App\Services\Attendance\AttendanceDayDisplay;
use App\Services\EmployeePortal\PortalAccess;
use App\Services\Support\TenantTimezone;
use Filament\Widgets\StatsOverviewWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;

class PortalOverview extends StatsOverviewWidget
{
    protected static bool $isLazy = false;

    protected ?string $pollingInterval = null;

    protected function getStats(): array
    {
        $employee = PortalAccess::employee();
        $date = today(TenantTimezone::get());
        $day = AttendanceDay::with('approvals')->where('user_id', $employee->id)->whereDate('work_date', $date)->first();
        $display = AttendanceDayDisplay::forDay($day);
        $monthly = ['present' => 0, 'absent' => 0, 'half_day' => 0];
        foreach (AttendanceDay::with('approvals')->where('user_id', $employee->id)
            ->whereBetween('work_date', [$date->copy()->startOfMonth()->toDateString(), $date->toDateString()])->get() as $record) {
            $status = AttendanceDayDisplay::forDay($record)['status'];
            if (array_key_exists($status, $monthly)) {
                $monthly[$status]++;
            }
        }
        $parameters = ['tenant' => tenant('shortname')];

        return [
            Stat::make('Today · '.$date->format('d M'), $day ? $display['clock'].'h' : '—')->description($display['label'])
                ->url(Calendar::getUrl($parameters, panel: 'employee')),
            Stat::make('Attendance reviews', AttendanceApproval::where('type', 'attendance')->where('state', 'pending')
                ->whereHas('attendanceDay', fn ($query) => $query->where('user_id', $employee->id))->count())
                ->description('Waiting for review')->url(Attendance::getUrl($parameters, panel: 'employee')),
            Stat::make('Leave requests', EmployeeLeave::where('user_id', $employee->id)->where('state', 'pending')->count())
                ->description('Waiting for review')->url(LeaveRequests::getUrl($parameters, panel: 'employee')),
            ...collect(['present' => 'Present', 'absent' => 'Absent', 'half_day' => 'Half days'])->map(
                fn ($label, $status) => Stat::make($label, $monthly[$status])->description($date->format('F Y').' · to date')
                    ->color(match ($status) {
                        'present' => 'success', 'absent' => 'danger', default => 'warning'
                    })
                    ->url(Attendance::getUrl($parameters, panel: 'employee'))
            )->values()->all(),
        ];
    }
}
