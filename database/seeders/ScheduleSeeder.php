<?php

namespace Database\Seeders;

use App\Models\Schedule;
use App\Services\Attendance\ShiftAssignmentService;
use App\Services\Attendance\ShiftRuleService;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class ScheduleSeeder extends Seeder
{
    public function run(): void
    {
        $shift = Schedule::updateOrCreate(['name' => 'General Shift'], [
            'type' => 'regular', 'status' => true, 'valid_from' => today(),
            'rules' => ['start_time' => '09:30', 'end_time' => '18:30', 'end_day_offset' => false, 'arrival_grace_minutes' => 15, 'departure_grace_minutes' => 15, 'earliest_arrival_minutes' => 60, 'half_day_minutes' => 120, 'full_day_minutes' => 480, 'punch_method' => 'first_last', 'overtime_basis' => 'after_required_hours', 'overtime_minimum_minutes' => 30, 'auto_checkout_enabled' => false, 'checkout_cutoff_minutes' => 180, 'weekdays' => [1, 2, 3, 4, 5, 6], 'breaks' => []],
        ]);
        app(ShiftRuleService::class)->sync($shift);
        if (! DB::table('default_shift_assignments')->exists()) {
            app(ShiftAssignmentService::class)->setDefault($shift, today()->toDateString());
        }
    }
}
