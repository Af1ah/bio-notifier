<?php

namespace App\Services\Attendance;

use App\Models\AttendanceLog;
use App\Models\User;
use Carbon\Carbon;
use Carbon\CarbonPeriod;

class ReportService
{
    public function generateReport(array $userIds, string $fromDate, string $toDate): array
    {
        $startDate = Carbon::parse($fromDate)->startOfDay();
        $endDate = Carbon::parse($toDate)->endOfDay();
        
        $period = CarbonPeriod::create($startDate, $endDate);
        
        $users = User::whereIn('id', $userIds)->get();
        $logs = AttendanceLog::whereIn('pin', $users->pluck('pin'))
            ->whereBetween('punched_at', [$startDate, $endDate])
            ->orderBy('punched_at', 'asc')
            ->get();
            
        $reportData = [];
        
        foreach ($users as $user) {
            $userLogs = $logs->where('pin', $user->pin);
            $dailyData = [];
            $totalMinutes = 0;
            $presentCount = 0;
            $absentCount = 0;
            
            foreach ($period as $date) {
                $dateString = $date->format('Y-m-d');
                $dayLogs = $userLogs->filter(function($log) use ($dateString) {
                    return Carbon::parse($log->punched_at)->format('Y-m-d') === $dateString;
                });
                
                if ($dayLogs->isEmpty()) {
                    $dailyData[$dateString] = [
                        'status' => 'A',
                        'hours' => 0,
                        'display' => 'Absent'
                    ];
                    if ($date->isWeekday()) {
                        $absentCount++;
                    }
                    continue;
                }
                
                $checkIns = $dayLogs->where('status', 0);
                $checkOuts = $dayLogs->where('status', 1);
                
                $firstIn = $checkIns->first() ? Carbon::parse($checkIns->first()->punched_at) : null;
                $lastOut = $checkOuts->last() ? Carbon::parse($checkOuts->last()->punched_at) : null;
                
                // If auto-punching (status mixed), fallback to first and last punch
                if (!$firstIn || !$lastOut) {
                    $firstIn = Carbon::parse($dayLogs->first()->punched_at);
                    $lastOut = Carbon::parse($dayLogs->last()->punched_at);
                }
                
                $minutes = 0;
                if ($firstIn && $lastOut && $firstIn->lt($lastOut)) {
                    $minutes = $firstIn->diffInMinutes($lastOut);
                }
                
                $totalMinutes += $minutes;
                $presentCount++;
                
                $hours = floor($minutes / 60);
                $mins = $minutes % 60;
                
                $dailyData[$dateString] = [
                    'status' => 'P',
                    'minutes' => $minutes,
                    'display' => sprintf('%dh %dm', $hours, $mins)
                ];
            }
            
            $reportData[] = [
                'user_name' => $user->name,
                'user_pin' => $user->pin,
                'daily' => $dailyData,
                'total_minutes' => $totalMinutes,
                'total_display' => sprintf('%dh %dm', floor($totalMinutes / 60), $totalMinutes % 60),
                'present' => $presentCount,
                'absent' => $absentCount
            ];
        }
        
        $periodArray = [];
        foreach ($period as $date) {
            $periodArray[] = [
                'date' => $date->format('Y-m-d'),
                'day' => $date->format('D'),
                'month_day' => $date->format('M d'),
            ];
        }
        
        return [
            'period' => $periodArray,
            'data' => $reportData
        ];
    }
}
