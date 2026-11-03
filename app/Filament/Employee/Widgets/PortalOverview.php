<?php

namespace App\Filament\Employee\Widgets;

use App\Filament\Employee\Pages\Attendance;
use App\Filament\Employee\Pages\Calendar;
use App\Filament\Employee\Pages\LeaveRequests;
use App\Models\AttendanceApproval;
use App\Models\AttendanceDay;
use App\Models\AttendanceLog;
use App\Models\EmployeeLeave;
use App\Models\PortalPunch;
use App\Models\User;
use App\Services\Attendance\AttendanceDayDisplay;
use App\Services\Attendance\AttendanceEntryContext;
use App\Services\EmployeePortal\PortalAccess;
use App\Services\Support\TenantTimezone;
use Filament\Widgets\StatsOverviewWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;
use Livewire\Attributes\On;

class PortalOverview extends StatsOverviewWidget
{
    protected static bool $isLazy = false;

    protected ?string $pollingInterval = null;

    protected string $view = 'filament.employee.widgets.portal-overview';

    #[On('portal-punch-submitted')]
    public function refreshSummary(): void
    {
        $this->cachedStats = null;
    }

    public function summary(): array
    {
        return $this->getCachedStats();
    }

    protected function getStats(): array
    {
        $employee = PortalAccess::employee();
        $date = today(TenantTimezone::get());
        $day = AttendanceDay::with('approvals')->where('user_id', $employee->id)->whereDate('work_date', $date)->first();
        $display = AttendanceDayDisplay::forDay($day);
        $start = $date->copy()->startOfDay()->toDateTimeString();
        $end = now(TenantTimezone::get())->toDateTimeString();
        $pendingIn = PortalPunch::where('user_id', $employee->id)->where('kind', 'in')->where('state', 'pending')
            ->whereBetween('punched_at', [$start, $end])->exists();
        $checkedIn = $this->hasCheckIn($employee, $start, $end);
        if (! $pendingIn && ! $checkedIn) {
            $previous = $date->copy()->subDay();
            $previousStart = $previous->copy()->startOfDay()->toDateTimeString();
            $previousEnd = $previous->copy()->endOfDay()->toDateTimeString();
            $hadPrevious = PortalPunch::where('user_id', $employee->id)->where('kind', 'in')->where('state', 'pending')
                ->whereBetween('punched_at', [$previousStart, $previousEnd])->exists()
                || $this->hasCheckIn($employee, $previousStart, $previousEnd);
            if ($hadPrevious) {
                foreach (app(AttendanceEntryContext::class)->candidates($employee, $previous->toDateString()) as $candidate) {
                    $rule = $candidate['rule'];
                    $overnight = (int) $rule->end_day_offset > 0 || $rule->end_time <= $rule->start_time;
                    if (! $overnight || ! now(TenantTimezone::get())->betweenIncluded($candidate['start'], $candidate['end'])) {
                        continue;
                    }
                    $windowStart = $candidate['start']->toDateTimeString();
                    $pendingIn = PortalPunch::where('user_id', $employee->id)->where('kind', 'in')->where('state', 'pending')
                        ->whereBetween('punched_at', [$windowStart, $end])->exists();
                    $checkedIn = $this->hasCheckIn($employee, $windowStart, $end);
                    if ($pendingIn || $checkedIn) {
                        break;
                    }
                }
            }
        }
        $todayValue = $day ? $display['clock'].'h' : '—';
        $todayLabel = $display['label'];
        if (! $display['unapproved_checkout'] && ($pendingIn || $checkedIn) && (! $day || in_array($display['status'], ['not_calculated', 'no_shift', 'absent', 'off', 'holiday', 'pending', 'pending_approval'], true))) {
            $todayValue = 'Present';
            $todayLabel = $pendingIn ? 'Pending approval' : 'Awaiting calculation';
        }

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
            Stat::make('Today · '.$date->format('d M'), $todayValue)->description($todayLabel)
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

    private function hasCheckIn(User $employee, string $start, string $end): bool
    {
        return AttendanceLog::where('pin', $employee->pin)->whereBetween('punched_at', [$start, $end])
            ->where(function ($query) {
                $query->whereIn('raw_data->device_direction', ['IN', 'INOUT'])
                    ->orWhere(fn ($query) => $query->whereIn('status', [0, 255])->where(fn ($query) => $query
                        ->whereNull('raw_data->device_direction')->orWhere('raw_data->device_direction', 'DEVICE')));
            })->exists();
    }
}
