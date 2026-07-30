<?php

namespace App\Filament\Master\Widgets;

use Filament\Widgets\StatsOverviewWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;

class SystemHealth extends StatsOverviewWidget
{
    protected static ?int $sort = 3;

    protected function getStats(): array
    {
        $pendingCommands = 0;
        $failedCommands = 0;

        foreach (\App\Models\Organisation::all() as $tenant) {
            $tenant->run(function () use (&$pendingCommands, &$failedCommands) {
                $pendingCommands += \App\Models\DeviceCommand::where('status', 'pending')->count();
                $failedCommands += \App\Models\DeviceCommand::where('status', 'failed')->count();
            });
        }

        return [
            Stat::make('Pending Device Commands', $pendingCommands)
                ->description('Commands waiting to be synced')
                ->descriptionIcon('heroicon-m-arrow-path')
                ->color('warning'),
            Stat::make('Failed Commands', $failedCommands)
                ->description('Commands that failed execution')
                ->descriptionIcon('heroicon-m-exclamation-triangle')
                ->color('danger'),
            Stat::make('Webhook Payloads', \App\Models\AdmsPayload::count())
                ->description('Total payloads received')
                ->descriptionIcon('heroicon-m-globe-alt')
                ->color('gray'),
        ];
    }
}
