<?php

namespace Tests\Feature;

use App\Models\AttendanceApproval;
use App\Models\AttendanceDay;
use App\Models\EmployeeLeave;
use App\Models\LeaveType;
use App\Models\SalarySlip;
use App\Models\User;
use App\Services\Payroll\SalarySlipService;
use Carbon\Carbon;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class PayrollWorkflowTest extends TestCase
{
    public function test_retroactive_holiday_updates_attendance_reports_and_draft_payroll(): void
    {
        $user = User::create(['name' => 'Holiday Employee', 'pin' => '900']);
        $user->payrollProfile()->create(['payroll_enabled' => true, 'basic_salary_minor' => 80_000, 'effective_from' => '2026-08-01']);
        AttendanceDay::create(['user_id' => $user->id, 'work_date' => '2026-08-25', 'status' => 'present', 'calculation_revision' => 1]);
        $day = AttendanceDay::create(['user_id' => $user->id, 'work_date' => '2026-08-26', 'status' => 'absent', 'calculation_revision' => 1]);
        $slip = app(SalarySlipService::class)->generate($user, Carbon::parse('2026-08-01'));
        $this->assertSame(2_581, $slip->attendance_deduction_minor);
        $holiday = \App\Models\Holiday::create(['name' => 'Onam', 'starts_on' => '2026-08-26', 'ends_on' => '2026-08-26', 'recurrence' => 'none', 'active' => true]);
        $this->assertSame('holiday', $day->fresh()->status);
        $this->assertSame(2, $day->fresh()->calculation_revision);
        $this->assertSame(0, $slip->fresh()->attendance_deduction_minor);
        $this->assertSame(80_000, $slip->fresh()->basic_salary_minor);
        $report = app(\App\Services\Attendance\ReportService::class)->generateReport([$user->id], '2026-08-26', '2026-08-26');
        $this->assertSame('O', $report['data'][0]['daily']['2026-08-26']['status']);
        $this->assertSame(0, $report['data'][0]['absent']);
        $this->assertSame(0, app(\App\Services\Attendance\HolidayAttendanceService::class)->reconcile(['2026-08-26']));
        $holiday->delete();
        $this->assertSame('no_shift', $day->fresh()->status);
    }

    public function test_holiday_leaves_are_not_deducted_and_reviewed_slips_are_flagged_not_rewritten(): void
    {
        $user = User::create(['name' => 'Holiday Employee', 'pin' => '901']);
        $user->payrollProfile()->create(['payroll_enabled' => true, 'basic_salary_minor' => 80_000, 'effective_from' => '2026-08-01']);
        AttendanceDay::create(['user_id' => $user->id, 'work_date' => '2026-08-25', 'status' => 'present', 'calculation_revision' => 1]);
        AttendanceDay::create(['user_id' => $user->id, 'work_date' => '2026-08-26', 'status' => 'absent', 'calculation_revision' => 1]);
        $type = LeaveType::create(['name' => 'Unpaid', 'code' => 'UP', 'is_paid' => false, 'annual_allowance_half_units' => 0, 'deduction_method' => 'salary_day_rate']);
        EmployeeLeave::create(['user_id' => $user->id, 'leave_type_id' => $type->id, 'leave_date' => '2026-08-26', 'duration' => 'full', 'state' => 'approved']);
        $slip = app(SalarySlipService::class)->generate($user, Carbon::parse('2026-08-01'));
        $slip->update(['status' => 'reviewed']);
        \App\Models\Holiday::create(['name' => 'Onam', 'starts_on' => '2026-08-26', 'ends_on' => '2026-08-26', 'recurrence' => 'none', 'active' => true]);
        $this->assertSame(40_000, $slip->fresh()->leave_deduction_minor);
        $this->assertTrue($slip->fresh()->calculation_snapshot['needs_recalculation']);
        $slip->update(['status' => 'draft']);
        $recalculated = app(SalarySlipService::class)->generate($user, Carbon::parse('2026-08-01'));
        $this->assertSame(0, $recalculated->leave_deduction_minor);
        $this->assertSame(0, $recalculated->unpaid_leave_half_units);
        $this->assertSame(1, $recalculated->scheduled_days);
        $this->assertArrayNotHasKey('needs_recalculation', $recalculated->calculation_snapshot);
    }

    public function test_holiday_reconciliation_observes_saved_coverage_and_overlapping_holidays(): void
    {
        $branch = \App\Models\Branch::create(['name' => 'Covered']);
        $covered = User::create(['name' => 'Covered', 'pin' => '902', 'branch_id' => $branch->id]);
        $other = User::create(['name' => 'Other', 'pin' => '903']);
        foreach ([$covered, $other] as $user) {
            AttendanceDay::create(['user_id' => $user->id, 'work_date' => '2026-08-26', 'status' => 'absent', 'calculation_revision' => 1]);
        }
        $holiday = DB::transaction(function () use ($branch) {
            $holiday = \App\Models\Holiday::create(['name' => 'Scoped', 'starts_on' => '2026-08-26', 'ends_on' => '2026-08-26', 'recurrence' => 'none', 'active' => true]);
            $holiday->branches()->sync([$branch->id]);

            return $holiday;
        });
        $this->assertSame('holiday', AttendanceDay::where('user_id', $covered->id)->first()->status);
        $this->assertSame('absent', AttendanceDay::where('user_id', $other->id)->first()->status);
        $this->assertSame(1, AttendanceDay::where('user_id', $other->id)->first()->calculation_revision);
        $overlap = \App\Models\Holiday::create(['name' => 'Overlap', 'starts_on' => '2026-08-26', 'ends_on' => '2026-08-26', 'recurrence' => 'none', 'active' => true]);
        $holiday->delete();
        $this->assertSame('holiday', AttendanceDay::where('user_id', $covered->id)->first()->status);
        $this->assertSame(2, AttendanceDay::where('user_id', $covered->id)->first()->calculation_revision);
        $overlap->update(['active' => false]);
        $this->assertSame('no_shift', AttendanceDay::where('user_id', $covered->id)->first()->status);
    }

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

    public function test_salary_slip_uses_attendance_leave_overtime_and_integer_money(): void
    {
        $user = User::create(['name' => 'Employee', 'pin' => '501', 'group' => 'Operator']);
        $user->payrollProfile()->create([
            'payroll_enabled' => true,
            'currency' => 'INR',
            'basic_salary_minor' => 3_000_000,
            'housing_allowance_minor' => 500_000,
            'transport_allowance_minor' => 100_000,
            'fixed_deduction_minor' => 50_000,
            'overtime_hourly_rate_minor' => 10_000,
            'effective_from' => '2026-09-01',
        ]);

        foreach ([
            ['2026-09-01', 'present', 60],
            ['2026-09-02', 'half_day', 0],
            ['2026-09-03', 'absent', 0],
            ['2026-09-04', 'absent', 0],
            ['2026-09-05', 'absent', 0],
            ['2026-09-06', 'off', 0],
            ['2026-09-07', 'holiday', 0],
        ] as [$date, $status, $overtime]) {
            AttendanceDay::create(['user_id' => $user->id, 'work_date' => $date, 'status' => $status, 'approved_overtime_minutes' => $overtime, 'calculation_revision' => 1]);
        }

        $paid = LeaveType::create(['name' => 'Casual Leave', 'code' => 'CL', 'annual_allowance_half_units' => 2, 'is_paid' => true, 'deduction_method' => 'salary_day_rate']);
        $unpaid = LeaveType::create(['name' => 'Loss of Pay', 'code' => 'LOP', 'annual_allowance_half_units' => 0, 'is_paid' => false, 'deduction_method' => 'fixed_amount', 'deduction_amount_minor' => 20_000]);
        EmployeeLeave::create(['user_id' => $user->id, 'leave_type_id' => $paid->id, 'leave_date' => '2026-09-04', 'duration' => 'full', 'state' => 'approved']);
        EmployeeLeave::create(['user_id' => $user->id, 'leave_type_id' => $unpaid->id, 'leave_date' => '2026-09-05', 'duration' => 'full', 'state' => 'approved']);

        $slip = app(SalarySlipService::class)->generate($user, Carbon::parse('2026-09-01'));

        $this->assertSame(5, $slip->scheduled_days);
        $this->assertSame(1, $slip->full_days);
        $this->assertSame(1, $slip->half_days);
        $this->assertSame(1, $slip->absent_days);
        $this->assertSame(2, $slip->paid_leave_half_units);
        $this->assertSame(2, $slip->unpaid_leave_half_units);
        $this->assertSame(3_610_000, $slip->gross_pay_minor);
        $this->assertSame(150_000, $slip->attendance_deduction_minor);
        $this->assertSame(20_000, $slip->leave_deduction_minor);
        $this->assertSame(220_000, $slip->total_deduction_minor);
        $this->assertSame(3_390_000, $slip->net_pay_minor);
        $this->assertSame('Operator', $slip->designation);
    }

    public function test_daily_pay_basis_pays_only_approved_full_and_half_days(): void
    {
        $user = User::create(['name' => 'Daily Employee', 'pin' => '507']);
        $user->payrollProfile()->create([
            'payroll_enabled' => true,
            'pay_basis' => 'daily',
            'basic_salary_minor' => 10_000,
            'housing_allowance_minor' => 5_000,
            'effective_from' => '2026-09-01',
        ]);
        AttendanceDay::create(['user_id' => $user->id, 'work_date' => '2026-09-01', 'status' => 'present', 'approved_worked_minutes' => 480, 'calculation_revision' => 1]);
        AttendanceDay::create(['user_id' => $user->id, 'work_date' => '2026-09-02', 'status' => 'half_day', 'approved_worked_minutes' => 240, 'calculation_revision' => 1]);
        AttendanceDay::create(['user_id' => $user->id, 'work_date' => '2026-09-03', 'status' => 'absent', 'calculation_revision' => 1]);
        AttendanceDay::create(['user_id' => $user->id, 'work_date' => '2026-09-04', 'status' => 'off', 'calculation_revision' => 1]);

        $slip = app(SalarySlipService::class)->generate($user, Carbon::parse('2026-09-01'));

        $this->assertSame(15_000, $slip->basic_salary_minor);
        $this->assertSame(0, $slip->housing_allowance_minor);
        $this->assertSame(0, $slip->attendance_deduction_minor);
        $this->assertSame('daily', $slip->calculation_snapshot['pay_basis']);
    }

    public function test_hourly_pay_basis_pays_approved_work_and_overtime_once(): void
    {
        $user = User::create(['name' => 'Hourly Employee', 'pin' => '508']);
        $user->payrollProfile()->create([
            'payroll_enabled' => true,
            'pay_basis' => 'hourly',
            'basic_salary_minor' => 12_000,
            'overtime_hourly_rate_minor' => 18_000,
            'effective_from' => '2026-09-01',
        ]);
        AttendanceDay::create([
            'user_id' => $user->id,
            'work_date' => '2026-09-01',
            'status' => 'present',
            'approved_worked_minutes' => 540,
            'approved_overtime_minutes' => 60,
            'calculation_revision' => 1,
        ]);

        $slip = app(SalarySlipService::class)->generate($user, Carbon::parse('2026-09-01'));

        $this->assertSame(96_000, $slip->basic_salary_minor);
        $this->assertSame(18_000, $slip->overtime_pay_minor);
        $this->assertSame(114_000, $slip->gross_pay_minor);
        $this->assertSame(0, $slip->attendance_deduction_minor);
        $this->assertSame('hourly', $slip->calculation_snapshot['pay_basis']);
    }

    public function test_draft_salary_slip_recalculation_replaces_the_existing_calculation(): void
    {
        $user = User::create(['name' => 'Recalculate Employee', 'pin' => '509']);
        $user->payrollProfile()->create(['payroll_enabled' => true, 'basic_salary_minor' => 300_000, 'effective_from' => '2026-09-01']);
        $day = AttendanceDay::create(['user_id' => $user->id, 'work_date' => '2026-09-01', 'status' => 'present', 'calculation_revision' => 1]);
        $service = app(SalarySlipService::class);
        $original = $service->generate($user, Carbon::parse('2026-09-01'));

        $day->update(['status' => 'absent', 'calculation_revision' => 2]);
        $recalculated = $service->generate($user, Carbon::parse('2026-09-01'));

        $this->assertSame($original->id, $recalculated->id);
        $this->assertSame(10_000, $recalculated->attendance_deduction_minor);
        $this->assertSame(2, $recalculated->calculation_version);
    }

    public function test_pending_attendance_approval_blocks_payroll_generation(): void
    {
        $user = User::create(['name' => 'Employee', 'pin' => '502']);
        $user->payrollProfile()->create(['payroll_enabled' => true, 'basic_salary_minor' => 1_000_000, 'effective_from' => '2026-09-01']);
        $day = AttendanceDay::create(['user_id' => $user->id, 'work_date' => '2026-09-01', 'status' => 'present', 'calculation_revision' => 1]);
        AttendanceApproval::create(['attendance_day_id' => $day->id, 'type' => 'attendance', 'state' => 'pending', 'calculation_revision' => 1, 'proposal' => ['reason' => 'missing_checkout']]);

        $this->expectException(ValidationException::class);
        app(SalarySlipService::class)->generate($user, Carbon::parse('2026-09-01'));
    }

    public function test_leave_deduction_can_use_basic_salary_divided_by_31_calendar_days(): void
    {
        $user = User::create(['name' => 'Employee', 'pin' => '506']);
        $user->payrollProfile()->create([
            'payroll_enabled' => true,
            'basic_salary_minor' => 3_100_000,
            'effective_from' => '2026-09-01',
        ]);
        AttendanceDay::create(['user_id' => $user->id, 'work_date' => '2026-09-01', 'status' => 'present', 'calculation_revision' => 1]);
        $leaveType = LeaveType::create([
            'name' => 'Calendar Rate Leave',
            'code' => 'CRL',
            'annual_allowance_half_units' => 0,
            'is_paid' => false,
            'deduction_method' => 'basic_salary_31_day_rate',
        ]);
        EmployeeLeave::create(['user_id' => $user->id, 'leave_type_id' => $leaveType->id, 'leave_date' => '2026-09-02', 'duration' => 'full', 'state' => 'approved']);
        EmployeeLeave::create(['user_id' => $user->id, 'leave_type_id' => $leaveType->id, 'leave_date' => '2026-09-03', 'duration' => 'half', 'state' => 'approved']);
        AttendanceDay::create(['user_id' => $user->id, 'work_date' => '2026-09-03', 'status' => 'half_day', 'approved_worked_minutes' => 240, 'calculation_revision' => 1]);

        $slip = app(SalarySlipService::class)->generate($user, Carbon::parse('2026-09-01'));

        $this->assertSame(103_333, $slip->calculation_snapshot['calendar_day_rate_minor']);
        $this->assertSame(155_000, $slip->leave_deduction_minor);
        $this->assertSame(0, $slip->attendance_deduction_minor);
        $this->assertSame(1, $slip->half_days);
    }

    public function test_payroll_readiness_explains_missing_required_data_without_creating_a_slip(): void
    {
        $user = User::create(['name' => 'Employee', 'pin' => '505']);
        $user->payrollProfile()->create([
            'payroll_enabled' => true,
            'basic_salary_minor' => 1_000_000,
            'effective_from' => '2026-09-01',
        ]);
        $service = app(SalarySlipService::class);

        $this->assertSame(
            ['Calculated attendance is missing for this payroll month.'],
            $service->readinessIssues($user, Carbon::parse('2026-09-01')),
        );
        $this->assertDatabaseCount('salary_slips', 0);

        AttendanceDay::create([
            'user_id' => $user->id,
            'work_date' => '2026-09-01',
            'status' => 'present',
            'calculation_revision' => 1,
        ]);

        $this->assertSame([], $service->readinessIssues($user->fresh(), Carbon::parse('2026-09-01')));
    }

    public function test_reviewed_slip_is_locked_against_recalculation_and_follows_workflow(): void
    {
        $user = User::create(['name' => 'Employee', 'pin' => '503']);
        $admin = User::create(['name' => 'Admin', 'pin' => '1', 'privilege' => 14]);
        $user->payrollProfile()->create(['payroll_enabled' => true, 'basic_salary_minor' => 1_000_000, 'effective_from' => '2026-09-01']);
        AttendanceDay::create(['user_id' => $user->id, 'work_date' => '2026-09-01', 'status' => 'present', 'calculation_revision' => 1]);
        $service = app(SalarySlipService::class);
        $slip = $service->generate($user, Carbon::parse('2026-09-01'));

        $this->assertSame('reviewed', $service->transition($slip, $admin, 'reviewed')->status);
        $this->assertSame('approved', $service->transition($slip->fresh(), $admin, 'approved')->status);
        $this->assertSame('paid', $service->transition($slip->fresh(), $admin, 'paid')->status);

        $this->expectException(ValidationException::class);
        $service->generate($user, Carbon::parse('2026-09-01'));
    }

    public function test_salary_slips_do_not_cross_tenant_database_boundaries(): void
    {
        $user = User::create(['name' => 'Tenant A Employee', 'pin' => '504']);
        $user->payrollProfile()->create(['payroll_enabled' => true, 'basic_salary_minor' => 1_000_000]);
        AttendanceDay::create(['user_id' => $user->id, 'work_date' => '2026-09-01', 'status' => 'present', 'calculation_revision' => 1]);
        app(SalarySlipService::class)->generate($user, Carbon::parse('2026-09-01'));
        $first = config('database.connections.sqlite.database');
        $second = tempnam('/tmp', 'bio-payroll-tenant-b-');

        config(['database.connections.sqlite.database' => $second]);
        DB::purge('sqlite');
        set_error_handler(static fn () => true);
        try {
            Artisan::call('migrate:fresh', ['--path' => database_path('migrations/tenant'), '--realpath' => true, '--force' => true]);
        } finally {
            restore_error_handler();
        }

        $this->assertSame(0, SalarySlip::count());
        $this->assertDatabaseMissing('users', ['name' => 'Tenant A Employee']);

        config(['database.connections.sqlite.database' => $first]);
        DB::purge('sqlite');
        @unlink($second);
    }
}
