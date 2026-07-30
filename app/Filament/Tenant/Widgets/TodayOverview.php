<?php

namespace App\Filament\Tenant\Widgets;

use Filament\Widgets\StatsOverviewWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;

class TodayOverview extends StatsOverviewWidget
{
    protected static ?int $sort = 1;

    protected function getStats(): array
    {
        $today = now()->toDateString();
        
        $totalUsers = \App\Models\User::count();
        $presentUsers = \App\Models\AttendanceLog::whereDate('punched_at', $today)
            ->distinct('pin')
            ->count('pin');
            
        $absentUsers = max(0, $totalUsers - $presentUsers);

        return [
            Stat::make('Total Employees', $totalUsers)
                ->description('Active employees')
                ->descriptionIcon('heroicon-m-users')
                ->color('primary'),
            Stat::make('Present Today', $presentUsers)
                ->description('Employees punched in today')
                ->descriptionIcon('heroicon-m-check-badge')
                ->color('success'),
            Stat::make('Absent Today', $absentUsers)
                ->description('Employees not punched in')
                ->descriptionIcon('heroicon-m-x-circle')
                ->color('danger'),
            Stat::make('Devices Online', \App\Models\Device::where('status', 'online')->count())
                ->description('Total devices connected')
                ->descriptionIcon('heroicon-m-signal')
                ->color('info'),
        ];
    }
}
