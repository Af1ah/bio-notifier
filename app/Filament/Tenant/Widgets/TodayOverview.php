<?php

namespace App\Filament\Tenant\Widgets;

use App\Filament\Tenant\Resources\DeviceResource;
use App\Filament\Tenant\Resources\UserResource;
use App\Models\Device;
use App\Services\Attendance\TodayAttendance;
use Filament\Widgets\StatsOverviewWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;

class TodayOverview extends StatsOverviewWidget
{
    protected static ?int $sort = 1;

    protected ?string $pollingInterval = '30s';

    protected string $view = 'filament.tenant.widgets.today-overview';

    public function summary(): array
    {
        return $this->getCachedStats();
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
            Stat::make(__('portal-ui.employees'), $totalUsers)
                ->description('View employees')
                ->descriptionIcon('heroicon-m-users')
                ->color('primary')->url($employeeUrl()),
            Stat::make(__('portal-ui.present'), $presentUsers)
                ->description('View present')
                ->descriptionIcon('heroicon-m-check-badge')
                ->color('success')->url($employeeUrl('present')),
            Stat::make(__('portal-ui.absent'), $absentUsers)
                ->description('View absent')
                ->descriptionIcon('heroicon-m-x-circle')
                ->color('danger')->url($employeeUrl('absent')),
            Stat::make(__('portal-ui.devices_online'), Device::connected()->count())
                ->description('View devices')
                ->descriptionIcon('heroicon-m-signal')
                ->color('info')->url(fn () => auth('web')->user()?->canManageDevices() ? DeviceResource::getUrl('index', ['filters' => ['connection' => ['value' => 'online']]]) : null),
        ];
    }
}
