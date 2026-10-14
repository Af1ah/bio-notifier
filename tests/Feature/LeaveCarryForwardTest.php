<?php

namespace Tests\Feature;

use App\Filament\Tenant\Pages\LeaveBalances;
use App\Filament\Tenant\Resources\AttendanceDays\AttendanceDayResource;
use App\Filament\Tenant\Resources\AttendanceDays\Pages\ListAttendanceDays;
use App\Filament\Tenant\Resources\EmployeeLeaves\Pages\CreateEmployeeLeave;
use App\Filament\Tenant\Resources\EmployeeLeaves\Pages\EditEmployeeLeave;
use App\Filament\Tenant\Resources\LeavePolicies\Pages\CreateLeavePolicy;
use App\Filament\Tenant\Resources\LeavePolicies\Pages\ListLeavePolicies;
use App\Models\AttendanceDay;
use App\Models\AttendanceLog;
use App\Models\CompensatoryAward;
use App\Models\Device;
use App\Models\EmployeeLeave;
use App\Models\Holiday;
use App\Models\LeaveCreditLot;
use App\Models\LeavePolicy;
use App\Models\LeaveType;
use App\Models\SalarySlip;
use App\Models\Schedule;
use App\Models\User;
use App\Services\Attendance\AttendanceCalculationService;
use App\Services\Attendance\ReportService;
use App\Services\Attendance\ShiftAssignmentService;
use App\Services\Attendance\ShiftRuleService;
use App\Services\Leave\CompensatoryLeaveService;
use App\Services\Leave\EmployeeLeaveService;
use App\Services\Leave\LeaveBalanceService;
use App\Services\Leave\LeavePolicyService;
use App\Services\Leave\LeaveSettingsService;
use App\Services\Payroll\SalarySlipService;
use Carbon\Carbon;
use Carbon\CarbonPeriod;
use Filament\Facades\Filament;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\URL;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;

class LeaveCarryForwardTest extends TestCase
{
    private User $actor;

    private User $user;

    private LeaveType $type;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-10-14 12:00:00');
        Artisan::call('migrate:fresh', ['--path' => database_path('migrations/tenant'), '--realpath' => true, '--force' => true]);
        $this->actor = User::create(['name' => 'HR', 'pin' => 'hr', 'privilege' => 14, 'is_system_account' => true]);
        $this->user = User::create(['name' => 'Employee', 'pin' => 'staff']);
        $this->type = LeaveType::create(['name' => 'Monthly leave', 'code' => 'ML', 'is_paid' => true, 'active' => true, 'annual_allowance_half_units' => 0, 'deduction_method' => 'salary_day_rate']);
        Filament::setCurrentPanel(Filament::getPanel('tenant'));
        URL::defaults(['tenant' => 'test']);
        $this->actingAs($this->actor);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function policy(array $rules = [], string $start = '2026-08-01', bool $default = true): LeavePolicy
    {
        return app(LeavePolicyService::class)->save(null, ['name' => 'Company policy', 'leave_type_id' => $this->type->id, 'tenant_default' => $default, 'effective_from' => $start,
            'reason' => 'Company HR reviewed policy', 'rules' => array_replace(LeavePolicyService::defaults(), $rules)], $this->actor);
    }

    private function leave(string $date, string $state = 'approved', string $duration = 'full', ?EmployeeLeave $existing = null): EmployeeLeave
    {
        return app(EmployeeLeaveService::class)->save($existing, ['user_id' => $this->user->id, 'leave_type_id' => $this->type->id, 'leave_date' => $date, 'duration' => $duration, 'state' => $state], $this->actor);
    }

    private function shift(): Schedule
    {
        $shift = Schedule::create(['name' => 'Weekdays', 'type' => 'regular', 'valid_from' => '2026-08-01', 'status' => true,
            'rules' => ['start_time' => '09:00', 'end_time' => '17:00', 'weekdays' => [1, 2, 3, 4, 5], 'full_day_minutes' => 480, 'half_day_minutes' => 240, 'punch_method' => 'device', 'overtime_basis' => 'after_required_hours', 'overtime_minimum_minutes' => 30]]);
        app(ShiftRuleService::class)->sync($shift);
        app(ShiftAssignmentService::class)->setDefault($shift, '2026-08-01');

        return $shift;
    }

    private function holidayWork(): AttendanceDay
    {
        $this->shift();
        Holiday::create(['name' => 'Company holiday', 'starts_on' => '2026-08-03', 'ends_on' => '2026-08-03', 'recurrence' => 'none', 'active' => true]);
        $device = Device::create(['serial_number' => 'LEAVE']);
        AttendanceLog::create(['device_id' => $device->id, 'pin' => 'staff', 'punched_at' => '2026-08-03 09:00', 'status' => 0]);
        AttendanceLog::create(['device_id' => $device->id, 'pin' => 'staff', 'punched_at' => '2026-08-03 17:00', 'status' => 1]);

        return app(AttendanceCalculationService::class)->calculate($this->user, Carbon::parse('2026-08-03'));
    }

    private function award(AttendanceDay $day, string $reward = 'leave'): CompensatoryAward
    {
        $s = app(CompensatoryLeaveService::class);

        return $s->award($day, $this->type->id, $reward, 'Verified holiday work', $s->signature($day), $this->actor);
    }

    private function rejects(callable $fn, string $message): void
    {
        try {
            $fn();
            $this->fail('Expected validation failure');
        } catch (ValidationException $e) {
            $this->assertStringContainsString($message, implode(' ', $e->errors()[array_key_first($e->errors())]));
        }
    }

    public function test_monthly_carry_cap_and_grant_retries_preserve_original_expiry(): void
    {
        $this->policy(['carry_cap_half_units' => 2, 'expiry_months' => 1]);
        $service = app(LeaveBalanceService::class);
        $this->assertSame(4, $service->balance($this->user, $this->type->id, Carbon::parse('2026-08-01'))['available_half_units']);
        $this->leave('2026-08-05', duration: 'half');
        $this->assertSame(6, $service->balance($this->user, $this->type->id, Carbon::parse('2026-09-01'))['available_half_units']);
        $aug = LeaveCreditLot::orderBy('id')->first();
        $this->assertSame('2026-09-30', $aug->expires_on->toDateString());
        $service->balance($this->user, $this->type->id, Carbon::parse('2026-09-30'));
        $this->assertSame(2, LeaveCreditLot::count());
        $this->assertSame(6, $service->balance($this->user, $this->type->id, Carbon::parse('2026-10-01'))['available_half_units']);
        $this->assertSame('2026-09-30', $aug->fresh()->expires_on->toDateString());
        $this->assertDatabaseHas('leave_ledger_entries', ['kind' => 'carry_limit', 'half_units' => -1]);
    }

    public function test_earliest_expiry_used_first_and_cancellation_does_not_extend_expiry(): void
    {
        $this->policy(['mode' => 'compensatory', 'grant_half_units' => 0]);
        $s = app(LeaveBalanceService::class);
        $s->opening($this->user, $this->type, 2, Carbon::parse('2026-08-01'), Carbon::parse('2026-08-31'), 'Verified opening', $this->actor, 'first');
        $later = $s->opening($this->user, $this->type, 2, Carbon::parse('2026-08-01'), Carbon::parse('2026-09-30'), 'Verified opening', $this->actor, 'second');
        $leave = $this->leave('2026-08-12', 'pending');
        $this->assertSame(0, $s->remaining(LeaveCreditLot::orderBy('id')->first()));
        $this->assertSame(2, $s->remaining($later));
        $this->leave('2026-08-12', 'cancelled', existing: $leave);
        $this->assertSame(2, $s->balance($this->user, $this->type->id, Carbon::parse('2026-09-01'))['available_half_units']);
        $this->assertDatabaseHas('leave_ledger_entries', ['kind' => 'release', 'half_units' => 2]);
    }

    public function test_requests_reserve_balance_and_excess_rolls_back_without_partial_allocations(): void
    {
        $this->policy(['grant_half_units' => 2]);
        $this->leave('2026-08-05', 'pending');
        $this->rejects(fn () => $this->leave('2026-08-06'), 'Insufficient');
        $this->assertSame(1, EmployeeLeave::count());
        $this->assertSame(1, DB::table('leave_allocations')->count());
    }

    public function test_calendar_proration_handles_leap_month_and_employment_bounds(): void
    {
        $this->user->payrollProfile()->create(['effective_from' => '2024-02-01', 'employment_starts_on' => '2024-02-15', 'employment_ends_on' => '2024-02-29']);
        $this->policy(['grant_half_units' => 8], '2024-02-01');
        $s = app(LeaveBalanceService::class);
        $this->assertSame(4, $s->balance($this->user, $this->type->id, Carbon::parse('2024-02-29'))['available_half_units']);
        $lot = LeaveCreditLot::first();
        $this->assertSame('2024-02-15', $lot->available_on->toDateString());
        $s->ensureGrants($this->user, $this->type->id, Carbon::parse('2024-04-01'));
        $this->assertSame(1, LeaveCreditLot::count());
    }

    public function test_future_unearned_grants_are_not_issued_and_end_period_grants_wait(): void
    {
        $this->policy(['grant_timing' => 'end']);
        app(LeaveBalanceService::class)->ensureGrants($this->user, $this->type->id, Carbon::parse('2026-12-31'));
        $this->assertSame(2, LeaveCreditLot::count());
        $this->assertSame('2026-09-30', Carbon::parse(LeaveCreditLot::max('available_on'))->toDateString());
    }

    public function test_disabled_carry_expires_at_month_end_and_fixed_cutoff_can_be_configured(): void
    {
        $this->policy(['carry_enabled' => false]);
        app(LeaveBalanceService::class)->ensureGrants($this->user, $this->type->id, Carbon::parse('2026-09-01'));
        $this->assertSame('2026-08-31', LeaveCreditLot::orderBy('id')->first()->expires_on->toDateString());
        $this->assertSame(4, app(LeaveBalanceService::class)->availableUnits($this->user, $this->type->id, Carbon::parse('2026-09-01')));
    }

    public function test_policy_assignment_selects_employee_rules_and_rejects_overlap(): void
    {
        $policy = $this->policy(['grant_half_units' => 6], default: false);
        $s = app(LeavePolicyService::class);
        $s->assign($policy, [$this->user->id], Carbon::parse('2026-08-01'), Carbon::parse('2026-08-31'), $this->actor);
        $this->rejects(fn () => $s->assign($policy, [$this->user->id], Carbon::parse('2026-08-01'), null, $this->actor), 'overlapping');
        $this->assertSame(6, app(LeaveBalanceService::class)->balance($this->user, $this->type->id, Carbon::parse('2026-08-10'))['available_half_units']);
        $this->assertNull($s->resolve($this->user, $this->type->id, Carbon::parse('2026-09-01')));
    }

    public function test_annual_entitlement_is_granted_once_and_revision_does_not_rewrite_existing_credit(): void
    {
        $policy = $this->policy(['mode' => 'annual', 'grant_half_units' => 24], '2026-01-01');
        app(LeaveBalanceService::class)->ensureGrants($this->user, $this->type->id, Carbon::parse('2026-10-01'));
        $this->assertSame(1, LeaveCreditLot::count());
        $this->assertSame(24, LeaveCreditLot::first()->granted_half_units);
        $s = app(LeavePolicyService::class);
        $s->save($policy, ['name' => 'Next year', 'leave_type_id' => $this->type->id, 'tenant_default' => true, 'effective_from' => '2027-01-01', 'reason' => 'New year entitlement', 'rules' => array_replace(LeavePolicyService::defaults(), ['mode' => 'annual', 'grant_half_units' => 30])], $this->actor);
        $this->assertSame(2, $policy->revisions()->count());
        $this->assertSame(24, LeaveCreditLot::first()->fresh()->granted_half_units);
    }

    public function test_paid_period_and_leave_work_overlap_are_blocked(): void
    {
        $this->policy();
        AttendanceDay::create(['user_id' => $this->user->id, 'work_date' => '2026-08-05', 'status' => 'present', 'approved_worked_minutes' => 480]);
        $this->rejects(fn () => $this->leave('2026-08-05'), 'overlaps');
        SalarySlip::create(['user_id' => $this->user->id, 'period_start' => '2026-08-01', 'period_end' => '2026-08-31', 'status' => 'paid', 'calculation_snapshot' => [], 'calculated_at' => now()]);
        $this->rejects(fn () => $this->leave('2026-08-06'), 'closed');
    }

    public function test_holiday_and_rostered_weekend_do_not_consume_leave(): void
    {
        $this->policy();
        $this->shift();
        $this->rejects(fn () => $this->leave('2026-08-02'), 'do not consume');
        Holiday::create(['name' => 'Leave test', 'starts_on' => '2026-08-03', 'ends_on' => '2026-08-03', 'recurrence' => 'none', 'active' => true]);
        $this->rejects(fn () => $this->leave('2026-08-03'), 'do not consume');
        $this->assertSame(0, EmployeeLeave::count());
    }

    public function test_holiday_device_work_earns_one_compensatory_credit_with_original_calendar(): void
    {
        $this->policy(['mode' => 'compensatory', 'grant_half_units' => 0, 'comp_enabled' => true]);
        $day = $this->holidayWork();
        $this->assertSame(480, $day->approved_worked_minutes);
        $this->assertSame('holiday', $day->explanation['calendar_kind']);
        $award = $this->award($day);
        $this->assertSame($award->id, $this->award($day)->id);
        $this->assertSame(1, CompensatoryAward::count());
        $this->assertSame(1, LeaveCreditLot::count());
        $lot = LeaveCreditLot::first();
        $this->assertSame('2026-08-04', $lot->available_on->toDateString());
        $this->assertSame('2026-11-02', $lot->expires_on->toDateString());
        $this->leave('2026-08-05');
        $this->assertSame(2, app(LeaveBalanceService::class)->payrollCoverage(EmployeeLeave::first()));
    }

    public function test_changed_attendance_blocks_credit_use_and_consumed_credit_cannot_be_silently_reversed(): void
    {
        $this->policy(['mode' => 'compensatory', 'grant_half_units' => 0, 'comp_enabled' => true]);
        $day = $this->holidayWork();
        $award = $this->award($day);
        $leave = $this->leave('2026-08-05');
        $this->rejects(fn () => app(CompensatoryLeaveService::class)->reverse($award, $this->actor, 'Correction'), 'already reserved or consumed');
        $day->update(['approved_worked_minutes' => 240]);
        $this->rejects(fn () => app(LeaveBalanceService::class)->payrollCoverage($leave), 'evidence changed');
        $this->assertSame(2, DB::table('leave_allocations')->where('state', 'consumed')->sum('half_units'));
    }

    public function test_reversal_and_reaward_retain_both_decisions_without_duplicate_balance(): void
    {
        $this->policy(['mode' => 'compensatory', 'grant_half_units' => 0, 'comp_enabled' => true]);
        $day = $this->holidayWork();
        $award = $this->award($day);
        app(CompensatoryLeaveService::class)->reverse($award, $this->actor, 'Wrong minutes');
        $this->assertSame(0, app(LeaveBalanceService::class)->availableUnits($this->user, $this->type->id, Carbon::parse('2026-10-14')));
        $day->update(['approved_worked_minutes' => 240]);
        $new = $this->award($day->fresh());
        $this->assertSame($award->id, $new->id);
        $this->assertSame(1, $new->half_units);
        $this->assertArrayHasKey('previous_award', $new->evidence);
        $this->assertSame(1, app(LeaveBalanceService::class)->availableUnits($this->user, $this->type->id, Carbon::parse('2026-10-14')));
    }

    public function test_reward_policy_prevents_both_and_additional_overtime_unless_configured(): void
    {
        $this->policy(['mode' => 'compensatory', 'grant_half_units' => 0, 'comp_enabled' => true]);
        $day = $this->holidayWork();
        $this->rejects(fn () => $this->award($day, 'both'), 'does not permit both');
        $day->update(['approved_overtime_minutes' => 30]);
        $this->rejects(fn () => $this->award($day->fresh()), 'Overtime is already approved');
        $this->assertSame(0, CompensatoryAward::count());
    }

    public function test_defaults_are_explicit_idempotent_and_settings_balance_pages_render(): void
    {
        Livewire::test(ListLeavePolicies::class)->callAction('starterPolicies', data: ['effective_from' => '2026-08-01', 'confirm' => true])->assertHasNoErrors();
        app(LeavePolicyService::class)->installDefaults(Carbon::parse('2026-08-01'), $this->actor);
        $this->assertSame(2, LeavePolicy::count());
        Livewire::test(LeaveBalances::class)->assertCanSeeTableRecords([$this->user]);
        Livewire::test(ListAttendanceDays::class)->assertSuccessful();
    }

    public function test_actual_leave_create_and_edit_actions_use_balance_approval_workflow(): void
    {
        $this->policy();
        Livewire::test(CreateEmployeeLeave::class)->fillForm(['user_id' => $this->user->id, 'leave_type_id' => $this->type->id, 'leave_date' => '2026-08-05', 'duration' => 'half', 'state' => 'pending'])->call('create')->assertHasNoFormErrors();
        $leave = EmployeeLeave::first();
        $this->assertTrue($leave->balance_managed);
        $this->assertDatabaseHas('leave_allocations', ['employee_leave_id' => $leave->id, 'state' => 'reserved', 'half_units' => 1]);
        Livewire::test(EditEmployeeLeave::class, ['record' => $leave->id])->fillForm(['state' => 'approved'])->call('save')->assertHasNoFormErrors();
        $this->assertDatabaseHas('leave_allocations', ['employee_leave_id' => $leave->id, 'state' => 'consumed', 'half_units' => 1]);
    }

    public function test_compensatory_action_reviews_the_selected_attendance_and_same_day_overlap_is_blocked(): void
    {
        $this->policy(['mode' => 'compensatory', 'grant_half_units' => 0, 'comp_enabled' => true, 'comp_same_day' => true]);
        $day = $this->holidayWork();
        Livewire::test(ListAttendanceDays::class)->filterTable('work_date', ['from' => '2026-08-01', 'to' => '2026-08-31'])->callTableAction('award', $day, ['leave_type_id' => $this->type->id, 'reward' => 'leave', 'reason' => 'Reviewed work', 'evidence_signature' => app(CompensatoryLeaveService::class)->signature($day)])->assertHasNoErrors();
        $this->assertSame('2026-08-03', LeaveCreditLot::first()->available_on->toDateString());
        $this->rejects(fn () => $this->leave('2026-08-03'), 'do not consume');
    }

    public function test_payroll_consumes_approved_allocations_and_includes_compensatory_payment(): void
    {
        $this->policy(['comp_enabled' => true, 'comp_payment_full_minor' => 50000]);
        $day = $this->holidayWork();
        $this->user->payrollProfile()->create(['payroll_enabled' => true, 'basic_salary_minor' => 310000, 'effective_from' => '2026-08-01']);
        $this->award($day, 'payment');
        $leave = $this->leave('2026-08-05');
        foreach (CarbonPeriod::create('2026-08-01', '2026-08-31') as $date) {
            if ($date->toDateString() === '2026-08-03') {
                continue;
            }
            AttendanceDay::create(['user_id' => $this->user->id, 'work_date' => $date->toDateString(), 'status' => $date->toDateString() === '2026-08-05' ? 'absent' : 'present', 'approved_worked_minutes' => $date->toDateString() === '2026-08-05' ? 0 : 480]);
        }
        $slip = app(SalarySlipService::class)->generate($this->user, Carbon::parse('2026-08-01'));
        $this->assertSame(2, $slip->paid_leave_half_units);
        $this->assertSame(0, $slip->leave_deduction_minor);
        $this->assertSame(50000, $slip->calculation_snapshot['compensatory_payment_minor']);
        $this->assertSame(360000, $slip->gross_pay_minor);
        $this->assertCount(1, $slip->calculation_snapshot['leave_balance_evidence']['allocations']);
        app(SalarySlipService::class)->transition($slip, $this->actor, 'reviewed');
        $this->rejects(fn () => $this->leave('2026-08-05', 'cancelled', existing: $leave), 'closed');
    }

    public function test_managed_historical_leave_cannot_bypass_balance_reconciliation(): void
    {
        $this->policy();
        $leave = EmployeeLeave::create(['user_id' => $this->user->id, 'leave_type_id' => $this->type->id, 'leave_date' => '2026-08-05', 'duration' => 'full', 'state' => 'approved']);
        $this->rejects(fn () => app(LeaveBalanceService::class)->payrollCoverage($leave), 'requires balance reconciliation');
    }

    public function test_non_admin_cannot_change_policy_or_balance_and_migration_rolls_back_cleanly(): void
    {
        $this->actingAs($this->user);
        $this->assertFalse(LeaveBalances::canAccess());
        $this->assertFalse(AttendanceDayResource::canViewAny());
        $this->actingAs($this->actor);
        $migration = require database_path('migrations/tenant/2026_10_14_140000_create_tenant_leave_credit_system.php');
        $migration->down();
        $migration->up();
        $this->assertTrue(DB::connection()->getSchemaBuilder()->hasTable('leave_credit_lots'));
    }

    public function test_calendar_settings_are_editable_and_custom_policy_form_uses_days(): void
    {
        Livewire::test(ListLeavePolicies::class)->callAction('calendarSettings', data: ['timezone' => 'Europe/London', 'expiry_warning_days' => 14])->assertHasNoErrors();
        $this->assertSame('Europe/London', LeaveSettingsService::timezone());
        Livewire::test(CreateLeavePolicy::class)->fillForm(['name' => 'Custom monthly policy', 'leave_type_id' => $this->type->id, 'tenant_default' => true, 'effective_from' => '2026-08-01', 'reason' => 'Company chose three days monthly', 'rules.grant_half_units' => 3.0])->call('create')->assertHasNoFormErrors();
        $this->assertSame(6, LeavePolicy::first()->revisions()->first()->rules['grant_half_units']);
        $this->assertSame(12, LeavePolicy::first()->revisions()->first()->rules['carry_cap_half_units']);
    }

    public function test_fixed_expiry_and_balance_cap_are_applied_to_each_credit(): void
    {
        $this->policy(['balance_cap_half_units' => 6, 'fixed_expiry_month' => 3, 'fixed_expiry_day' => 28]);
        $s = app(LeaveBalanceService::class);
        $this->assertSame(6, $s->balance($this->user, $this->type->id, Carbon::parse('2026-10-01'))['available_half_units']);
        $this->assertSame('2027-03-28', LeaveCreditLot::first()->expires_on->toDateString());
        $this->assertSame(3, LeaveCreditLot::count());
        $this->assertSame(0, LeaveCreditLot::orderByDesc('id')->first()->granted_half_units);
        $this->leave('2026-10-05');
        $s->ensureGrants($this->user, $this->type->id, Carbon::parse('2026-10-14'));
        $this->assertSame(3, LeaveCreditLot::count());
    }

    public function test_day_and_hour_workers_receive_paid_leave_earnings_from_allocations(): void
    {
        $this->policy(['prorate_join_exit' => false]);
        $this->shift();
        $this->user->payrollProfile()->create(['payroll_enabled' => true, 'pay_basis' => 'daily', 'basic_salary_minor' => 10000, 'effective_from' => '2026-08-01', 'employment_starts_on' => '2026-08-05', 'employment_ends_on' => '2026-08-05']);
        $this->leave('2026-08-05');
        AttendanceDay::create(['user_id' => $this->user->id, 'work_date' => '2026-08-05', 'status' => 'absent']);
        $slip = app(SalarySlipService::class)->generate($this->user, Carbon::parse('2026-08-01'));
        $this->assertSame(10000, $slip->gross_pay_minor);
        $this->assertSame(10000, $slip->calculation_snapshot['paid_leave_pay_minor']);
        $this->user->payrollProfile()->update(['pay_basis' => 'hourly', 'basic_salary_minor' => 1000]);
        $slip = app(SalarySlipService::class)->generate($this->user, Carbon::parse('2026-08-01'));
        $this->assertSame(8000, $slip->gross_pay_minor);
        $this->assertSame(2, $slip->paid_leave_half_units);
    }

    public function test_compensatory_payment_currency_must_match_employee_payroll(): void
    {
        $this->policy(['mode' => 'compensatory', 'grant_half_units' => 0, 'comp_enabled' => true, 'comp_payment_full_minor' => 50000]);
        $this->user->payrollProfile()->create(['currency' => 'USD', 'effective_from' => '2026-08-01']);
        $day = $this->holidayWork();
        $this->rejects(fn () => $this->award($day, 'payment'), 'currency must match');
    }

    public function test_approved_off_day_device_work_is_counted_and_not_hard_coded_to_weekend(): void
    {
        $this->policy(['mode' => 'compensatory', 'grant_half_units' => 0, 'comp_enabled' => true]);
        $this->shift();
        $device = Device::create(['serial_number' => 'OFF-DAY']);
        AttendanceLog::create(['device_id' => $device->id, 'pin' => 'staff', 'punched_at' => '2026-08-02 09:00', 'status' => 0]);
        AttendanceLog::create(['device_id' => $device->id, 'pin' => 'staff', 'punched_at' => '2026-08-02 17:00', 'status' => 1]);
        $day = app(AttendanceCalculationService::class)->calculate($this->user, Carbon::parse('2026-08-02'));
        $this->assertSame('off', $day->explanation['calendar_kind']);
        $this->assertSame(480, $day->approved_worked_minutes);
        $this->assertSame(2, $this->award($day)->half_units);
    }

    public function test_reductions_are_audited_and_cannot_remove_reserved_credit(): void
    {
        $this->policy();
        $this->leave('2026-08-05', 'pending');
        $lot = LeaveCreditLot::first();
        $s = app(LeaveBalanceService::class);
        $this->rejects(fn () => $s->reduce($lot, 3, 'Verified correction', $this->actor, 'too-much'), 'exceeds unreserved');
        $s->reduce($lot, 1, 'Verified correction', $this->actor, 'approved');
        $s->reduce($lot, 1, 'Verified correction', $this->actor, 'approved');
        $this->assertSame(1, $s->remaining($lot));
        $this->assertSame(1, DB::table('leave_ledger_entries')->where('kind', 'adjustment')->count());
        $this->assertSame(4, $lot->fresh()->granted_half_units);
    }

    public function test_assignment_can_end_with_audit_before_switching_policy(): void
    {
        $first = $this->policy(default: false);
        $second = $this->policy(['grant_half_units' => 6], default: false);
        $s = app(LeavePolicyService::class);
        $s->assign($first, [$this->user->id], Carbon::parse('2026-08-01'), null, $this->actor);
        $id = DB::table('employee_leave_policy_assignments')->value('id');
        $s->endAssignment($id, Carbon::parse('2026-10-31'), 'New employee policy from November', $this->actor);
        $s->assign($second, [$this->user->id], Carbon::parse('2026-11-01'), null, $this->actor);
        $this->assertSame($second->id, $s->resolve($this->user, $this->type->id, Carbon::parse('2026-11-01'))->leave_policy_id);
        $this->assertStringContainsString('New employee policy', DB::table('employee_leave_policy_assignments')->where('id', $id)->value('changes'));
    }

    public function test_validation_errors_are_visible_in_leave_form_and_reward_modal(): void
    {
        $this->policy(['mode' => 'compensatory', 'grant_half_units' => 0, 'comp_enabled' => true]);
        Livewire::test(CreateEmployeeLeave::class)->fillForm(['user_id' => $this->user->id, 'leave_type_id' => $this->type->id, 'leave_date' => '2026-08-05', 'duration' => 'full', 'state' => 'approved'])->call('create')->assertHasFormErrors(['leave_date']);
        $day = $this->holidayWork();
        Livewire::test(ListAttendanceDays::class)->filterTable('work_date', ['from' => '2026-08-01', 'to' => '2026-08-31'])->callTableAction('award', $day, ['leave_type_id' => $this->type->id, 'reward' => 'both', 'reason' => 'Incorrect combination', 'evidence_signature' => app(CompensatoryLeaveService::class)->signature($day)])->assertHasErrors(['mountedActions.0.data.reason']);
        $this->assertSame(0, CompensatoryAward::count());
    }

    public function test_cutoff_day_31_uses_short_month_end_without_overflow(): void
    {
        $this->policy(['fixed_expiry_month' => 2, 'fixed_expiry_day' => 31], '2023-08-01');
        app(LeaveBalanceService::class)->ensureGrants($this->user, $this->type->id, Carbon::parse('2023-08-01'));
        $this->assertSame('2024-02-29', LeaveCreditLot::first()->expires_on->toDateString());
    }

    public function test_retried_leave_approval_keeps_allocations_and_ledger_unchanged(): void
    {
        $this->policy();
        $leave = $this->leave('2026-08-05');
        $entries = DB::table('leave_ledger_entries')->count();
        $allocations = DB::table('leave_allocations')->count();
        $this->leave('2026-08-05', existing: $leave);
        $this->assertSame($entries, DB::table('leave_ledger_entries')->count());
        $this->assertSame($allocations, DB::table('leave_allocations')->count());
    }

    public function test_balance_actions_display_days_and_record_reviewed_adjustments(): void
    {
        $this->policy(['mode' => 'compensatory', 'grant_half_units' => 0]);
        Livewire::test(LeaveBalances::class)->callTableAction('opening', $this->user, ['leave_type_id' => $this->type->id, 'half_units' => 2.0, 'available_on' => '2026-08-01', 'expires_on' => '2026-12-31', 'reason' => 'Verified opening', 'request_key' => 'reviewed-opening'])->assertHasNoErrors();
        $lot = LeaveCreditLot::first();
        $this->assertSame(4, $lot->granted_half_units);
        Livewire::test(LeaveBalances::class)->callTableAction('reduce', $this->user, ['lot_id' => $lot->id, 'days' => 0.5, 'reason' => 'Verified correction', 'request_key' => 'reviewed-reduction'])->assertHasNoErrors();
        $this->assertSame(3, app(LeaveBalanceService::class)->remaining($lot));
    }

    public function test_stale_compensatory_credit_is_not_displayed_as_available(): void
    {
        $this->policy(['mode' => 'compensatory', 'grant_half_units' => 0, 'comp_enabled' => true]);
        $day = $this->holidayWork();
        $this->award($day);
        $day->update(['approved_worked_minutes' => 240]);
        $this->assertSame(0, app(LeaveBalanceService::class)->balance($this->user, $this->type->id, Carbon::parse('2026-10-14'))['available_half_units']);
    }

    public function test_service_authorization_rejects_employee_policy_and_balance_changes(): void
    {
        try {
            app(LeaveSettingsService::class)->save(['timezone' => 'UTC', 'expiry_warning_days' => 1], $this->user);
            $this->fail('Expected authorization failure');
        } catch (HttpException $e) {
            $this->assertSame(403, $e->getStatusCode());
        }
        try {
            app(LeaveBalanceService::class)->opening($this->user, $this->type, 2, Carbon::parse('2026-08-01'), Carbon::parse('2026-12-31'), 'Unauthorised', $this->user, 'invalid');
            $this->fail('Expected authorization failure');
        } catch (HttpException $e) {
            $this->assertSame(403, $e->getStatusCode());
        }
        $this->assertSame(0, LeaveCreditLot::count());
    }

    public function test_company_can_limit_carry_to_one_following_month(): void
    {
        $this->policy(['carry_roll_again' => false]);
        app(LeaveBalanceService::class)->ensureGrants($this->user, $this->type->id, Carbon::parse('2026-08-10'));
        $this->assertSame('2026-09-30', LeaveCreditLot::first()->expires_on->toDateString());
    }

    public function test_attendance_row_shows_reward_details_and_can_reverse_the_award(): void
    {
        $this->policy(['mode' => 'compensatory', 'grant_half_units' => 0, 'comp_enabled' => true]);
        $day = $this->holidayWork();
        $this->award($day);
        $page = Livewire::test(ListAttendanceDays::class)->assertSee('Holiday')->assertSee('1 day leave')->assertSee('Approved')
            ->assertTableActionHidden('award', $day)->mountTableAction('viewReward', $day);
        $page->assertMountedActionModalSee(['Earned paid leave: 1', '03 Aug 2026']);
        Livewire::test(ListAttendanceDays::class)->callTableAction('reverse', $day, ['reason' => 'HR reviewed an incorrect reward'])->assertHasNoErrors()->assertSee('Reversed');
        $this->assertSame('reversed', CompensatoryAward::first()->state);
        $this->assertDatabaseHas('leave_ledger_entries', ['kind' => 'reversal', 'half_units' => -2]);
        Livewire::test(ListAttendanceDays::class)->assertTableActionVisible('award', $day);
    }

    public function test_regular_working_day_does_not_show_compensatory_award_action(): void
    {
        $this->policy(['mode' => 'compensatory', 'grant_half_units' => 0, 'comp_enabled' => true]);
        $day = AttendanceDay::create(['user_id' => $this->user->id, 'work_date' => '2026-08-05', 'status' => 'present', 'approved_worked_minutes' => 480, 'explanation' => ['calendar_kind' => 'working']]);
        Livewire::test(ListAttendanceDays::class)->assertCanSeeTableRecords([$day])->assertTableActionHidden('award', $day)->assertTableActionHidden('reverse', $day)->assertTableActionHidden('viewReward', $day);
    }

    public function test_compensatory_award_does_not_inflate_attendance_report_or_change_leave_date_status(): void
    {
        $this->policy(['mode' => 'compensatory', 'grant_half_units' => 0, 'comp_enabled' => true]);
        $day = $this->holidayWork();
        $leaveDay = AttendanceDay::create(['user_id' => $this->user->id, 'work_date' => '2026-08-05', 'status' => 'absent']);
        $report = app(ReportService::class);
        $before = $report->generateReport([$this->user->id], '2026-08-03', '2026-08-05');
        $this->award($day);
        $this->leave('2026-08-05');
        $after = $report->generateReport([$this->user->id], '2026-08-03', '2026-08-05');
        $this->assertSame($before, $after);
        $this->assertSame(480, $after['data'][0]['total_minutes']);
        $this->assertSame('P', $after['data'][0]['daily']['2026-08-03']['status']);
        $this->assertSame('A', $after['data'][0]['daily']['2026-08-05']['status']);
        $this->assertSame('absent', $leaveDay->fresh()->status);
        $this->assertSame(2, app(LeaveBalanceService::class)->payrollCoverage(EmployeeLeave::first()));
    }

    public function test_separate_compensatory_page_is_no_longer_registered(): void
    {
        $this->assertFalse(Route::has('filament.tenant.pages.compensatory-leave'));
        $this->assertTrue(Route::has('filament.tenant.resources.attendance-days.index'));
    }
}
