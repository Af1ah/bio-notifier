<?php

namespace App\Filament\Master\Widgets;

use Filament\Widgets\StatsOverviewWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;

class SystemOverview extends StatsOverviewWidget
{
    protected ?string $pollingInterval = "30s";

    protected static ?int $sort = 1;

    protected function getStats(): array
    {
        $totalDevices = 0;
        $totalUsers = 0;

        foreach (\App\Models\Organisation::all() as $tenant) {
            $tenant->run(function () use (&$totalDevices, &$totalUsers) {
                $totalDevices += \App\Models\Device::count();
                $totalUsers += \App\Models\User::count();
            });
        }

        return [
            Stat::make('Total Organisations', \App\Models\Organisation::count())
                ->description('Total registered tenants')
                ->descriptionIcon('heroicon-m-building-office-2')
                ->color('primary'),
            Stat::make('Total Devices', $totalDevices)
                ->description('Biometric devices system-wide')
                ->descriptionIcon('heroicon-m-cpu-chip')
                ->color('success'),
            Stat::make('Total Users', $totalUsers)
                ->description('Employees system-wide')
                ->descriptionIcon('heroicon-m-users')
                ->color('info'),
        ];
    }
}
