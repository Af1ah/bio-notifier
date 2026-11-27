<?php

namespace App\Filament\Tenant\Widgets;

use App\Models\AttendanceLog;
use App\Models\User;
use Filament\Widgets\ChartWidget;

class AttendanceTrend extends ChartWidget
{
    protected ?string $pollingInterval = "30s";

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
        $employees = User::employees();
        $totalUsers = (clone $employees)->count();

        $days = (int) $this->filter;
        $today = now()->toImmutable();
        $counts = AttendanceLog::query()
            ->whereDate('punched_at', '>=', $today->subDays($days - 1)->toDateString())
            ->whereDate('punched_at', '<=', $today->toDateString())
            ->whereIn('pin', $employees->select('pin'))
            ->selectRaw('DATE(punched_at) as punch_date, COUNT(DISTINCT pin) as present_count')
            ->groupByRaw('DATE(punched_at)')
            ->pluck('present_count', 'punch_date');

        for ($i = $days - 1; $i >= 0; $i--) {
            $date = $today->subDays($i);
            $dateString = $date->toDateString();
            $labels[] = $date->format('M d');

            $present = (int) $counts->get($dateString, 0);

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
