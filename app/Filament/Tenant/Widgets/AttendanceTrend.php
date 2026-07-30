<?php

namespace App\Filament\Tenant\Widgets;

use Filament\Widgets\ChartWidget;

class AttendanceTrend extends ChartWidget
{
    protected static ?int $sort = 2;
    protected int | string | array $columnSpan = 1;
    protected ?string $heading = 'Attendance Trend';
    public ?string $filter = '7';

    protected function getFilters(): ?array
    {
        return [
            '7' => 'Last 7 days',
            '30' => 'Last 30 days',
            '90' => 'Last 3 months',
        ];
    }

    protected function getData(): array
    {
        $labels = [];
        $presentData = [];
        $absentData = [];
        $totalUsers = \App\Models\User::count();

        $days = (int) $this->filter;

        for ($i = $days - 1; $i >= 0; $i--) {
            $date = now()->subDays($i);
            $dateString = $date->toDateString();
            $labels[] = $date->format('M d');

            $present = \App\Models\AttendanceLog::whereDate('punched_at', $dateString)
                ->distinct('pin')
                ->count('pin');

            $presentData[] = $present;
            $absentData[] = max(0, $totalUsers - $present);
        }

        return [
            'datasets' => [
                [
                    'label' => 'Present',
                    'data' => $presentData,
                    'borderColor' => '#10b981', // success
                    'backgroundColor' => 'rgba(16, 185, 129, 0.1)',
                    'fill' => true,
                ],
                [
                    'label' => 'Absent',
                    'data' => $absentData,
                    'borderColor' => '#ef4444', // danger
                    'backgroundColor' => 'rgba(239, 68, 68, 0.1)',
                    'fill' => true,
                ],
            ],
            'labels' => $labels,
        ];
    }

    protected function getType(): string
    {
        return 'line';
    }
}
