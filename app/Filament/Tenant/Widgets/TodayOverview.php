<?php

namespace App\Filament\Tenant\Widgets;

use App\Filament\Tenant\Resources\DeviceResource;
use App\Filament\Tenant\Resources\UserResource;
use App\Models\Device;
use App\Services\Attendance\TodayAttendance;
use Filament\Widgets\StatsOverviewWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;
use Illuminate\Support\HtmlString;

class TodayOverview extends StatsOverviewWidget
{
    protected static ?int $sort = 1;

    protected ?string $pollingInterval = '30s';

    protected function getColumns(): array|int|null
    {
        return [
            'default' => 2,
            'md' => 4,
        ];
    }

    protected function getStats(): array
    {
        $totalUsers = TodayAttendance::employees()->count();
        $presentUsers = TodayAttendance::filterEmployees(TodayAttendance::employees(), true)->count();
        $absentUsers = max(0, $totalUsers - $presentUsers);

        $employeeUrl = fn (?string $attendance = null): string => UserResource::getUrl('index', [
            'filters' => [
                'is_enabled' => ['value' => '1'],
                'attendance_today' => ['value' => $attendance],
            ],
        ]);

        return [
            Stat::make(new HtmlString('Total <span class="hidden md:inline">Employees</span>'), $totalUsers)
                ->description(new HtmlString('Active <span class="hidden md:inline">employees</span>'))
                ->descriptionIcon('heroicon-m-users')
                ->color('primary')->url($employeeUrl()),
            Stat::make(new HtmlString('Present <span class="hidden md:inline">Today</span>'), $presentUsers)
                ->description(new HtmlString('Punched in <span class="hidden md:inline">today</span>'))
                ->descriptionIcon('heroicon-m-check-badge')
                ->color('success')->url($employeeUrl('present')),
            Stat::make(new HtmlString('Absent <span class="hidden md:inline">Today</span>'), $absentUsers)
                ->description(new HtmlString('No punch <span class="hidden md:inline">today</span>'))
                ->descriptionIcon('heroicon-m-x-circle')
                ->color('danger')->url($employeeUrl('absent')),
            Stat::make(new HtmlString('Devices <span class="hidden md:inline">Online</span>'), Device::connected()->count())
                ->description(new HtmlString('Connected <span class="hidden md:inline">devices</span>'))
                ->descriptionIcon('heroicon-m-signal')
                ->color('info')->url(fn () => auth('web')->user()?->canManageDevices() ? DeviceResource::getUrl('index', ['filters' => ['connection' => ['value' => 'online']]]) : null),
        ];
    }
}
