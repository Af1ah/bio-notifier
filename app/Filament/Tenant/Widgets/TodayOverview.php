<?php

namespace App\Filament\Tenant\Widgets;

use Filament\Widgets\StatsOverviewWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;

class TodayOverview extends StatsOverviewWidget
{
    protected static ?int $sort = 1;

    protected function getColumns(): array | int | null
    {
        return [
            'default' => 2,
            'md' => 4,
        ];
    }

    protected function getStats(): array
    {
        $today = now()->toDateString();
        
        $totalUsers = \App\Models\User::count();
        $presentUsers = \App\Models\AttendanceLog::whereDate('punched_at', $today)
            ->distinct('pin')
            ->count('pin');
            
        $absentUsers = max(0, $totalUsers - $presentUsers);

        return [
            Stat::make(new \Illuminate\Support\HtmlString('Total <span class="hidden md:inline">Employees</span>'), $totalUsers)
                ->description(new \Illuminate\Support\HtmlString('Active <span class="hidden md:inline">employees</span>'))
                ->descriptionIcon('heroicon-m-users')
                ->color('primary'),
            Stat::make(new \Illuminate\Support\HtmlString('Present <span class="hidden md:inline">Today</span>'), $presentUsers)
                ->description(new \Illuminate\Support\HtmlString('Punched in <span class="hidden md:inline">today</span>'))
                ->descriptionIcon('heroicon-m-check-badge')
                ->color('success'),
            Stat::make(new \Illuminate\Support\HtmlString('Absent <span class="hidden md:inline">Today</span>'), $absentUsers)
                ->description(new \Illuminate\Support\HtmlString('No punch <span class="hidden md:inline">today</span>'))
                ->descriptionIcon('heroicon-m-x-circle')
                ->color('danger'),
            Stat::make(new \Illuminate\Support\HtmlString('Devices <span class="hidden md:inline">Online</span>'), \App\Models\Device::where('status', 'online')->count())
                ->description(new \Illuminate\Support\HtmlString('Connected <span class="hidden md:inline">devices</span>'))
                ->descriptionIcon('heroicon-m-signal')
                ->color('info'),
        ];
    }
}
