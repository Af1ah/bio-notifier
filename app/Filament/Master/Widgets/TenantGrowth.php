<?php

namespace App\Filament\Master\Widgets;

use Filament\Widgets\ChartWidget;

class TenantGrowth extends ChartWidget
{
    protected static ?int $sort = 2;
    protected int | string | array $columnSpan = 1;
    protected ?string $heading = 'Tenant Growth (Last 30 Days)';

    protected function getData(): array
    {
        $data = \App\Models\Organisation::selectRaw('DATE(created_at) as date, COUNT(*) as count')
            ->where('created_at', '>=', now()->subDays(30))
            ->groupBy('date')
            ->orderBy('date')
            ->get();

        return [
            'datasets' => [
                [
                    'label' => 'Organisations Registered',
                    'data' => $data->pluck('count')->toArray(),
                    'fill' => 'start',
                ],
            ],
            'labels' => $data->pluck('date')->toArray(),
        ];
    }

    protected function getType(): string
    {
        return 'line';
    }
}
