<?php

namespace Tests\Unit;

use App\Services\Attendance\AttendanceCalculationService;
use PHPUnit\Framework\TestCase;

class AttendanceCalculationServiceTest extends TestCase
{
    public function test_daily_hour_completion_is_aggregated_across_multiple_shifts(): void
    {
        $this->assertSame('present', AttendanceCalculationService::dailyStatus(4 * 60 + 4 * 60));
        $this->assertSame('present', AttendanceCalculationService::dailyStatus(3 * 60 + 5 * 60));
        $this->assertSame('half_day', AttendanceCalculationService::dailyStatus(3 * 60 + 3 * 60));
    }

    public function test_half_day_and_absence_thresholds_are_explicit(): void
    {
        $this->assertSame('absent', AttendanceCalculationService::dailyStatus(119));
        $this->assertSame('half_day', AttendanceCalculationService::dailyStatus(120));
        $this->assertSame('present', AttendanceCalculationService::dailyStatus(480));
    }
}
