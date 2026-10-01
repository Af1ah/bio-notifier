<?php

namespace Tests\Feature;

use App\Filament\Tenant\Resources\AttendanceApprovals\Pages\ListAttendanceApprovals;
use App\Models\AttendanceApproval;
use App\Models\AttendanceDay;
use App\Models\AttendanceLog;
use App\Models\Device;
use App\Models\Holiday;
use App\Models\Schedule;
use App\Models\User;
use App\Services\Attendance\ApprovalService;
use App\Services\Attendance\AttendanceCalculationService;
use App\Services\Attendance\ShiftAssignmentResolver;
use App\Services\Attendance\ShiftAssignmentService;
use App\Services\Attendance\ShiftRuleService;
use Carbon\Carbon;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class AttendanceWorkflowTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        set_error_handler(static fn () => true);
        try {
            Artisan::call('migrate:fresh', ['--path' => database_path('migrations/tenant'), '--realpath' => true, '--force' => true]);
        } finally {
            restore_error_handler();
        }
    }

    private function shift(string $name = 'General', string $start = '09:30', string $end = '18:30'): Schedule
    {
        $s = Schedule::create(['name' => $name, 'type' => 'regular', 'valid_from' => today(), 'status' => true, 'rules' => ['start_time' => $start, 'end_time' => $end, 'weekdays' => [1, 2, 3, 4, 5, 6], 'arrival_grace_minutes' => 15, 'departure_grace_minutes' => 15, 'earliest_arrival_minutes' => 60, 'half_day_minutes' => 120, 'full_day_minutes' => 480, 'punch_method' => 'first_last', 'overtime_basis' => 'after_required_hours', 'overtime_minimum_minutes' => 30, 'checkout_cutoff_minutes' => 180]]);
        app(ShiftRuleService::class)->sync($s);

        return $s;
    }

    public function test_disabling_a_holiday_restores_scheduled_absence(): void
    {
        Carbon::setTestNow('2026-08-26 23:00:00');
        try {
            $user = User::create(['name' => 'Employee', 'pin' => '904']);
            $shift = $this->shift();
            app(ShiftAssignmentService::class)->assign('user', $user, ['schedule_ids' => [$shift->id], 'effective_from' => today()->toDateString()]);
            $day = app(AttendanceCalculationService::class)->calculate($user, today());
            $this->assertSame('absent', $day->status);
            $holiday = Holiday::create(['name' => 'Onam', 'starts_on' => today(), 'ends_on' => today(), 'recurrence' => 'none', 'active' => true]);
            $this->assertSame('holiday', $day->fresh()->status);
            $holiday->update(['active' => false]);
            $this->assertSame('absent', $day->fresh()->status);
        } finally {
            Carbon::setTestNow();
        }
    }

    public function test_shift_edits_create_immutable_rule_revisions(): void
    {
        $s = $this->shift();
        $this->assertCount(1, $s->ruleRevisions);
        $s->update(['rules' => array_merge($s->rules, ['arrival_grace_minutes' => 20]), 'valid_from' => today()->addDay()]);
        app(ShiftRuleService::class)->sync($s);
        $this->assertCount(2, $s->ruleRevisions()->get());
        $this->assertSame(15, $s->ruleRevisions()->oldest('revision')->first()->arrival_grace_minutes);
    }

    public function test_backdated_shift_can_be_assigned_to_history_and_uses_effective_rules(): void
    {
        Carbon::setTestNow('2026-10-01 23:00:00');
        try {
            $shift = $this->shift();
            $assignments = app(ShiftAssignmentService::class);
            $assignments->setDefault($shift, '2026-10-01');
            $oldRevision = $shift->ruleRevisions()->first();
            $shift->update(['valid_from' => '2026-05-01', 'rules' => array_merge($shift->rules, ['full_day_minutes' => 600])]);
            $newRevision = app(ShiftRuleService::class)->sync($shift);
            $this->assertNull($oldRevision->fresh()->effective_to);
            $assignments->setDefault($shift, '2026-08-01', '2026-09-30');
            // Repeating the repair must not create a backwards assignment range.
            $assignments->setDefault($shift, '2026-08-01', '2026-09-30');
            $this->assertSame(0, DB::table('default_shift_assignments')->whereColumn('effective_to', '<', 'effective_from')->count());
            $user = User::create(['name' => 'Historical', 'pin' => 'history']);
            $device = Device::create(['serial_number' => 'HISTORY']);
            foreach (['2026-08-04', '2026-10-01'] as $date) {
                AttendanceLog::create(['device_id' => $device->id, 'pin' => $user->pin, 'punched_at' => $date.' 09:30:00', 'status' => 0]);
                AttendanceLog::create(['device_id' => $device->id, 'pin' => $user->pin, 'punched_at' => $date.' 18:30:00', 'status' => 1]);
                $day = app(AttendanceCalculationService::class)->calculate($user, Carbon::parse($date));
                $this->assertSame('half_day', $day->status);
                $this->assertSame(540, $day->candidate_worked_minutes);
                $this->assertDatabaseHas('attendance_occurrences', ['attendance_day_id' => $day->id, 'shift_rule_revision_id' => $newRevision->id]);
            }
        } finally {
            Carbon::setTestNow();
        }
    }

    public function test_legacy_weekly_schedule_is_backfilled_to_rule_revision(): void
    {
        $schedule = Schedule::create(['name' => 'Regular Shift', 'type' => 'regular', 'status' => true, 'rules' => ['weekly' => ['breaks' => [], 'days' => ['monday' => ['is_working' => true, 'start' => '09:00', 'end' => '18:00', 'breaks' => []]]]]]);
        $migration = require database_path('migrations/tenant/2026_09_24_083428_backfill_legacy_schedules.php');
        $migration->up();
        $this->assertDatabaseHas('shift_rule_revisions', ['schedule_id' => $schedule->id, 'start_time' => '09:00', 'end_time' => '18:00']);
        $this->assertDatabaseHas('default_shift_assignments', ['active' => true]);
    }

    public function test_direct_assignment_wins_over_default(): void
    {
        $default = $this->shift();
        $direct = $this->shift('Direct', '10:00', '19:00');
        $user = User::create(['name' => 'A', 'pin' => '1']);
        $service = app(ShiftAssignmentService::class);
        $service->setDefault($default, today()->toDateString());
        $service->assign('user', $user, ['schedule_ids' => [$direct->id], 'effective_from' => today()->toDateString()]);
        $resolved = app(ShiftAssignmentResolver::class)->resolve($user, today());
        $this->assertSame($direct->id, $resolved->slots->first()->schedule_id);
    }

    public function test_calculation_preserves_punches_and_creates_daily_result_and_ot_approval(): void
    {
        $shift = $this->shift();
        $user = User::create(['name' => 'A', 'pin' => '7']);
        app(ShiftAssignmentService::class)->assign('user', $user, ['schedule_ids' => [$shift->id], 'effective_from' => today()->toDateString()]);
        $device = Device::create(['serial_number' => 'TEST']);
        AttendanceLog::create(['device_id' => $device->id, 'pin' => '7', 'punched_at' => today()->setTime(9, 30), 'status' => 0]);
        AttendanceLog::create(['device_id' => $device->id, 'pin' => '7', 'punched_at' => today()->setTime(18, 30), 'status' => 1]);
        $day = app(AttendanceCalculationService::class)->calculate($user, Carbon::today());
        $this->assertSame('present', $day->status);
        $this->assertSame(540, $day->candidate_worked_minutes);
        $this->assertSame(2, AttendanceLog::count());
        $this->assertDatabaseHas('attendance_approvals', ['attendance_day_id' => $day->id, 'type' => 'overtime', 'state' => 'pending']);
        app(AttendanceCalculationService::class)->calculate($user, Carbon::today());
        $this->assertDatabaseHas('attendance_day_revisions', ['attendance_day_id' => $day->id, 'revision' => 1]);
    }

    public function test_second_level_punches_store_whole_late_and_early_minutes(): void
    {
        $shift = $this->shift();
        $user = User::create(['name' => 'Seconds', 'pin' => 'seconds']);
        app(ShiftAssignmentService::class)->assign('user', $user, ['schedule_ids' => [$shift->id], 'effective_from' => today()->toDateString()]);
        $device = Device::create(['serial_number' => 'SECONDS']);
        AttendanceLog::create(['device_id' => $device->id, 'pin' => 'seconds', 'punched_at' => today()->setTime(9, 50, 31), 'status' => 0]);
        AttendanceLog::create(['device_id' => $device->id, 'pin' => 'seconds', 'punched_at' => today()->setTime(18, 2, 3), 'status' => 1]);

        $occurrence = app(AttendanceCalculationService::class)->calculate($user, today())->occurrences->first();

        $this->assertSame(5, $occurrence->late_minutes);
        $this->assertSame(12, $occurrence->early_minutes);
    }

    public function test_yearly_holiday_generates_occurrences(): void
    {
        $holiday = Holiday::create(['name' => 'Founding Day', 'starts_on' => '2024-02-29', 'ends_on' => '2024-02-29', 'recurrence' => 'yearly', 'start_year' => 2024, 'end_year' => 2028, 'active' => true]);
        $this->assertSame(['2024-02-29', '2028-02-29'], $holiday->occurrences()->orderBy('holiday_date')->pluck('holiday_date')->map->format('Y-m-d')->all());
    }

    public function test_even_saturday_holiday_generates_second_and_fourth_saturdays(): void
    {
        $holiday = Holiday::create([
            'name' => 'Even Saturdays',
            'starts_on' => '2026-01-01',
            'ends_on' => '2026-03-31',
            'recurrence' => 'monthly_weekday',
            'monthly_weekday' => 6,
            'monthly_pattern' => 'even',
            'active' => true,
        ]);

        $this->assertSame(
            ['2026-01-10', '2026-01-24', '2026-02-14', '2026-02-28', '2026-03-14', '2026-03-28'],
            $holiday->occurrences()->orderBy('holiday_date')->pluck('holiday_date')->map->format('Y-m-d')->all(),
        );
    }

    public function test_custom_monthly_weekday_holiday_supports_selected_weeks(): void
    {
        $holiday = Holiday::create([
            'name' => 'First and fourth Saturdays',
            'starts_on' => '2026-02-10',
            'ends_on' => '2026-04-15',
            'recurrence' => 'monthly_weekday',
            'monthly_weekday' => 6,
            'monthly_pattern' => 'custom',
            'monthly_weeks' => [1, 4],
            'active' => true,
        ]);

        $this->assertSame(
            ['2026-02-28', '2026-03-07', '2026-03-28', '2026-04-04'],
            $holiday->occurrences()->orderBy('holiday_date')->pluck('holiday_date')->map->format('Y-m-d')->all(),
        );
    }

    public function test_attendance_and_overtime_approvals_are_independent(): void
    {
        $user = User::create(['name' => 'Admin', 'pin' => '9', 'privilege' => 14]);
        $day = AttendanceDay::create(['user_id' => $user->id, 'work_date' => today(), 'status' => 'present', 'candidate_overtime_minutes' => 60, 'calculation_revision' => 1]);
        $attendance = AttendanceApproval::create(['attendance_day_id' => $day->id, 'type' => 'attendance', 'state' => 'pending', 'calculation_revision' => 1, 'proposal' => ['reason' => 'manual']]);
        $ot = AttendanceApproval::create(['attendance_day_id' => $day->id, 'type' => 'overtime', 'state' => 'pending', 'calculation_revision' => 1, 'proposal' => ['minutes' => 60]]);
        app(ApprovalService::class)->decide($attendance, $user, true);
        $this->assertSame('pending', $ot->fresh()->state);
        app(ApprovalService::class)->decide($ot, $user, true);
        $this->assertSame(60, $day->fresh()->approved_overtime_minutes);
    }

    public function test_approval_tabs_receive_and_scope_their_queries(): void
    {
        $tabs = app(ListAttendanceApprovals::class)->getTabs();

        $attendanceQuery = $tabs['attendance']->modifyQuery(AttendanceApproval::query());
        $overtimeQuery = $tabs['overtime']->modifyQuery(AttendanceApproval::query());

        $this->assertStringContainsString('"type" = ?', $attendanceQuery->toSql());
        $this->assertSame(['attendance'], $attendanceQuery->getBindings());
        $this->assertStringContainsString('"type" = ?', $overtimeQuery->toSql());
        $this->assertSame(['overtime'], $overtimeQuery->getBindings());
    }

    public function test_multiple_shifts_are_aggregated_without_counting_the_gap(): void
    {
        $a = $this->shift('Morning', '09:00', '13:00');
        $b = $this->shift('Evening', '14:00', '18:00');
        $user = User::create(['name' => 'A', 'pin' => '20']);
        app(ShiftAssignmentService::class)->assign('user', $user, ['schedule_ids' => [$a->id, $b->id], 'daily_policy_schedule_id' => $a->id, 'effective_from' => today()->toDateString()]);
        $device = Device::create(['serial_number' => 'MULTI']);
        foreach ([['09:00', 0], ['13:00', 1], ['14:00', 0], ['18:00', 1]] as [$time,$status]) {
            AttendanceLog::create(['device_id' => $device->id, 'pin' => '20', 'punched_at' => Carbon::parse(today()->toDateString().' '.$time), 'status' => $status]);
        }$day = app(AttendanceCalculationService::class)->calculate($user, today());
        $this->assertSame(480, $day->candidate_worked_minutes);
        $this->assertSame('present', $day->status);
        $this->assertCount(2, $day->occurrences);
    }

    public function test_overlapping_assignments_are_rejected(): void
    {
        $shift = $this->shift();
        $user = User::create(['name' => 'A', 'pin' => '30']);
        $service = app(ShiftAssignmentService::class);
        $service->assign('user', $user, ['schedule_ids' => [$shift->id], 'effective_from' => today()->toDateString()]);
        $this->expectException(ValidationException::class);
        $service->assign('user', $user, ['schedule_ids' => [$shift->id], 'effective_from' => today()->addDay()->toDateString()]);
    }

    public function test_tenant_database_switch_does_not_expose_users_from_previous_database(): void
    {
        $first = config('database.connections.sqlite.database');
        User::create(['name' => 'Tenant A', 'pin' => 'same']);
        $second = tempnam('/tmp', 'bio-tenant-b-');
        config(['database.connections.sqlite.database' => $second]);
        DB::purge('sqlite');
        set_error_handler(static fn () => true);
        try {
            Artisan::call('migrate:fresh', ['--path' => database_path('migrations/tenant'), '--realpath' => true, '--force' => true]);
        } finally {
            restore_error_handler();
        }$this->assertDatabaseMissing('users', ['name' => 'Tenant A']);
        User::create(['name' => 'Tenant B', 'pin' => 'same']);
        $this->assertDatabaseHas('users', ['name' => 'Tenant B']);
        config(['database.connections.sqlite.database' => $first]);
        DB::purge('sqlite');
        @unlink($second);
    }
}
