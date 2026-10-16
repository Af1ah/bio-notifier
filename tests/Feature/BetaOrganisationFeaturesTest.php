<?php

namespace Tests\Feature;

use App\Models\AttendanceDay;
use App\Models\AttendanceLog;
use App\Models\Device;
use App\Models\EmployeeLeave;
use App\Models\Holiday;
use App\Models\LeaveCreditLot;
use App\Models\LeaveType;
use App\Models\PayrollTemplate;
use App\Models\Schedule;
use App\Models\TaskGroup;
use App\Models\User;
use App\Services\Attendance\ApprovalService;
use App\Services\Attendance\AttendanceCalculationService;
use App\Services\Attendance\ShiftAssignmentService;
use App\Services\Attendance\ShiftRuleService;
use App\Services\Leave\CompensatoryLeaveService;
use App\Services\Leave\LeaveBalanceService;
use App\Services\Leave\LeavePolicyService;
use App\Services\Leave\LeaveRangeService;
use App\Services\Payroll\PayrollTemplateService;
use App\Services\Payroll\SalarySlipService;
use Carbon\Carbon;
use Carbon\CarbonPeriod;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class BetaOrganisationFeaturesTest extends TestCase
{
    private User $hr;

    private User $user;

    private LeaveType $type;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2027-02-01 12:00:00');
        Artisan::call('migrate:fresh', ['--path' => database_path('migrations/tenant'), '--realpath' => true, '--force' => true]);
        $this->hr = User::create(['name' => 'HR', 'pin' => 'hr', 'privilege' => 14, 'is_system_account' => true]);
        $this->user = User::create(['name' => 'Employee', 'pin' => 'e']);
        $this->type = LeaveType::create(['name' => 'Paid', 'code' => 'PL', 'is_paid' => true, 'active' => true]);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function policy(array $extra = [], string $start = '2026-01-01')
    {
        return app(LeavePolicyService::class)->save(null, ['name' => 'Company', 'leave_type_id' => $this->type->id, 'tenant_default' => true, 'effective_from' => $start, 'reason' => 'Approved company settings', 'rules' => array_replace(LeavePolicyService::defaults(), ['prorate_join_exit' => false, 'carry_cap_half_units' => 40, 'balance_cap_half_units' => 40], $extra)], $this->hr);
    }

    private function shift(array $extra = []): Schedule
    {
        $s = Schedule::create(['name' => 'Shift', 'type' => 'regular', 'valid_from' => '2026-01-01', 'status' => true, 'rules' => array_replace(['start_time' => '09:00', 'end_time' => '17:00', 'weekdays' => [1, 2, 3, 4, 5, 6], 'full_day_minutes' => 480, 'half_day_minutes' => 240, 'punch_method' => 'device', 'checkout_cutoff_minutes' => 180, 'overtime_basis' => 'after_required_hours', 'overtime_minimum_minutes' => 30], $extra)]);
        app(ShiftRuleService::class)->sync($s);

        return $s;
    }

    private function work(string $date, string $in, string $out): AttendanceDay
    {
        $d = Device::firstOrCreate(['serial_number' => 'BETA']);
        foreach ([[$in, 0], [$out, 1]] as [$time,$status]) {
            AttendanceLog::create(['device_id' => $d->id, 'pin' => $this->user->pin, 'punched_at' => $time, 'status' => $status]);
        }

        return app(AttendanceCalculationService::class)->calculate($this->user, Carbon::parse($date));
    }

    private function rejects(callable $f, string $message): void
    {
        try {
            $f();
            $this->fail('Expected validation failure');
        } catch (ValidationException $e) {
            $this->assertStringContainsString($message, implode(' ', collect($e->errors())->flatten()->all()));
        }
    }

    public function test_outreach_two_start_of_day_resets_keep_new_june_and_january_grants(): void
    {
        $this->policy(['grant_half_units' => 2, 'expiry_method' => 'resets', 'reset_dates' => [['date' => '2026-06-01'], ['date' => '2026-12-31']]], '2026-05-01');
        $b = app(LeaveBalanceService::class);
        $this->assertSame(2, $b->balance($this->user, $this->type->id, Carbon::parse('2026-05-31'))['available_half_units']);
        $this->assertSame(2, $b->balance($this->user, $this->type->id, Carbon::parse('2026-06-01'))['available_half_units']);
        $this->assertSame('2026-05-31', LeaveCreditLot::orderBy('id')->first()->expires_on->toDateString());
        $this->assertSame('2026-12-30', LeaveCreditLot::orderByDesc('id')->first()->expires_on->toDateString());
        $this->assertSame(14, $b->balance($this->user, $this->type->id, Carbon::parse('2026-12-30'))['available_half_units']);
        $this->assertSame(0, $b->balance($this->user, $this->type->id, Carbon::parse('2026-12-31'))['available_half_units']);
        $this->assertSame(2, $b->balance($this->user, $this->type->id, Carbon::parse('2027-01-01'))['available_half_units']);
        $this->assertSame(9, LeaveCreditLot::count());
    }

    public function test_month_end_compensatory_leave_uses_work_month_even_when_approved_late(): void
    {
        $s = $this->shift();
        app(ShiftAssignmentService::class)->setDefault($s, '2026-01-01');
        $this->policy(['mode' => 'compensatory', 'comp_expiry_mode' => 'work_month_end', 'comp_reward' => 'leave']);
        Holiday::create(['name' => 'Holiday', 'starts_on' => '2026-01-12', 'ends_on' => '2026-01-12', 'recurrence' => 'none', 'active' => true]);
        $day = $this->work('2026-01-12', '2026-01-12 09:00', '2026-01-12 17:00');
        $svc = app(CompensatoryLeaveService::class);
        $award = $svc->award($day, $this->type->id, 'leave', 'Approved work', $svc->signature($day), $this->hr);
        $lot = LeaveCreditLot::findOrFail($award->leave_credit_lot_id);
        $this->assertSame('2026-01-13', $lot->available_on->toDateString());
        $this->assertSame('2026-01-31', $lot->expires_on->toDateString());
        $this->assertSame(2, app(LeaveBalanceService::class)->availableUnits($this->user, $this->type->id, Carbon::parse('2026-01-31')));
        $this->assertSame(0, app(LeaveBalanceService::class)->availableUnits($this->user, $this->type->id, Carbon::parse('2026-02-01')));
    }

    public function test_last_day_reward_does_not_silently_extend_into_next_month(): void
    {
        app(ShiftAssignmentService::class)->setDefault($this->shift(), '2026-01-01');
        $this->policy(['mode' => 'compensatory', 'comp_expiry_mode' => 'work_month_end', 'comp_reward' => 'leave']);
        Holiday::create(['name' => 'Last day', 'starts_on' => '2026-01-31', 'ends_on' => '2026-01-31', 'recurrence' => 'none', 'active' => true]);
        $day = $this->work('2026-01-31', '2026-01-31 09:00', '2026-01-31 17:00');
        $svc = app(CompensatoryLeaveService::class);
        $this->rejects(fn () => $svc->award($day, $this->type->id, 'leave', 'Approved', $svc->signature($day), $this->hr), 'no usable');
        $this->assertSame(0, LeaveCreditLot::count());
    }

    public function test_disabled_overtime_has_no_candidates_or_hourly_premium(): void
    {
        app(ShiftAssignmentService::class)->setDefault($this->shift(['overtime_enabled' => false]), '2026-01-01');
        $day = $this->work('2026-01-12', '2026-01-12 09:00', '2026-01-12 18:00');
        $this->assertSame(540, $day->approved_worked_minutes);
        $this->assertSame(0, $day->candidate_overtime_minutes);
        $this->assertSame(0, $day->approvals()->where('type', 'overtime')->count());
    }

    public function test_day_and_night_overtime_rates_are_snapshotted_and_exports_show_the_same_breakdown(): void
    {
        $this->user->payrollProfile()->create(['payroll_enabled' => true, 'pay_basis' => 'hourly', 'basic_salary_minor' => 10000, 'overtime_hourly_rate_minor' => 5000, 'currency' => 'INR', 'effective_from' => '2026-01-01']);
        $dayShift = $this->shift(['overtime_hourly_rate_minor' => 10000, 'overtime_currency' => 'INR']);
        $night = $this->shift(['start_time' => '22:00', 'end_time' => '06:00', 'end_day_offset' => 1, 'weekdays' => [0, 1, 2, 3, 4, 5, 6], 'overtime_hourly_rate_minor' => 20000, 'overtime_currency' => 'INR']);
        app(ShiftAssignmentService::class)->setDefault($dayShift, '2026-01-01');
        app(ShiftAssignmentService::class)->assign('user', $this->user, ['schedule_ids' => [$night->id], 'effective_from' => '2026-01-16', 'effective_to' => '2026-01-16']);
        foreach ([['2026-01-10', '2026-01-10 09:00', '2026-01-10 18:00'], ['2026-01-16', '2026-01-16 22:00', '2026-01-17 07:00']] as [$date,$in,$out]) {
            $day = $this->work($date, $in, $out);
            app(ApprovalService::class)->decide($day->approvals()->where('type', 'overtime')->firstOrFail(), $this->hr, true);
        }
        $this->user->payrollProfile()->update(['overtime_hourly_rate_minor' => 99999]);
        foreach (CarbonPeriod::create('2026-01-01', '2026-01-31') as $date) {
            app(AttendanceCalculationService::class)->calculate($this->user, $date);
        }
        $slip = app(SalarySlipService::class)->generate($this->user, Carbon::parse('2026-01-01'));
        $this->assertSame(30000, $slip->overtime_pay_minor);
        $this->assertSame(160000, $slip->basic_salary_minor);
        $this->assertSame([10000, 20000], array_column($slip->calculation_snapshot['overtime_breakdown'], 'hourly_rate_minor'));
        $this->assertSame(30000, array_sum(array_column($slip->calculation_snapshot['overtime_breakdown'], 'amount_minor')));
        $this->assertStringContainsString('200.00/hour', $slip->overtime_breakdown_display);
        $html = view('filament.tenant.pages.pdf.salary-slips', ['organisation' => 'Test', 'slips' => collect([$slip]), 'totals' => collect([])])->render();
        $this->assertStringContainsString($slip->overtime_breakdown_display, $html);
    }

    public function test_range_preview_rolls_back_then_submission_skips_off_days_and_retries_once(): void
    {
        app(ShiftAssignmentService::class)->setDefault($this->shift(['weekdays' => [1, 2, 3, 4, 5]]), '2026-01-01');
        $this->policy(['grant_half_units' => 4, 'allow_unpaid_excess' => true]);
        $data = ['user_id' => $this->user->id, 'leave_type_id' => $this->type->id, 'starts_on' => '2026-01-09', 'ends_on' => '2026-01-14', 'duration' => 'full', 'state' => 'approved'];
        $svc = app(LeaveRangeService::class);
        $p = $svc->preview($data, $this->hr);
        $this->assertSame(0, EmployeeLeave::count());
        $this->assertSame(0, LeaveCreditLot::count());
        $this->assertSame(4, array_sum(array_column($p['rows'], 'paid_half_units')));
        $this->assertSame(4, array_sum(array_column($p['rows'], 'unpaid_half_units')));
        $key = (string) Str::uuid();
        $first = $svc->submit($data, $key, $p['signature'], $this->hr);
        $again = $svc->submit($data, $key, $p['signature'], $this->hr);
        $this->assertSame($first, $again);
        $this->assertSame(4, EmployeeLeave::count());
        $this->assertSame(1, DB::table('leave_range_requests')->count());
    }

    public function test_range_failure_is_atomic_and_does_not_leave_partial_reservations(): void
    {
        app(ShiftAssignmentService::class)->setDefault($this->shift(), '2026-01-01');
        $this->policy(['grant_half_units' => 2, 'allow_unpaid_excess' => false]);
        $data = ['user_id' => $this->user->id, 'leave_type_id' => $this->type->id, 'starts_on' => '2026-01-12', 'ends_on' => '2026-01-13', 'duration' => 'full', 'state' => 'pending'];
        $this->rejects(fn () => app(LeaveRangeService::class)->preview($data, $this->hr), 'Insufficient');
        $this->assertSame(0, EmployeeLeave::count());
        $this->assertSame(0, DB::table('leave_allocations')->count());
    }

    public function test_task_group_holiday_scope_does_not_make_a_holiday_global(): void
    {
        $other = User::create(['name' => 'Other', 'pin' => 'other']);
        $group = TaskGroup::create(['name' => 'Role holiday']);
        $this->user->taskGroups()->sync([$group->id]);
        $holiday = DB::transaction(function () use ($group) {
            $h = Holiday::create(['name' => 'Scoped', 'starts_on' => '2026-01-09', 'ends_on' => '2026-01-21', 'recurrence' => 'none', 'active' => true]);
            $h->taskGroups()->sync([$group->id]);

            return $h;
        });
        $calc = app(AttendanceCalculationService::class);
        $this->assertTrue($calc->isHoliday($this->user, Carbon::parse('2026-01-09')));
        $this->assertTrue($calc->isHoliday($this->user, Carbon::parse('2026-01-10')));
        $this->assertTrue($calc->isHoliday($this->user, Carbon::parse('2026-01-21')));
        $this->assertFalse($calc->isHoliday($other, Carbon::parse('2026-01-12')));
    }

    public function test_payroll_templates_update_enabled_profiles_and_leave_disabled_profiles_untouched(): void
    {
        $this->user->payrollProfile()->create(['payroll_enabled' => true, 'basic_salary_minor' => 9000, 'effective_from' => '2026-01-01']);
        $other = User::create(['name' => 'Override', 'pin' => 'override']);
        $other->payrollProfile()->create(['payroll_enabled' => false, 'basic_salary_minor' => 12345, 'effective_from' => '2026-01-01']);
        $t = PayrollTemplate::create(['name' => 'Hourly', 'effective_from' => '2026-01-01', 'settings' => ['pay_basis' => 'hourly', 'currency' => 'INR', 'basic_salary_minor' => 10000, 'overtime_hourly_rate_minor' => 20000, 'housing_allowance_minor' => 0, 'transport_allowance_minor' => 0, 'other_allowance_minor' => 0, 'fixed_deduction_minor' => 0]]);
        $svc = app(PayrollTemplateService::class);
        $this->assertSame(['applied' => 1, 'disabled' => 1], $svc->apply($t, [$this->user->id, $other->id], $this->hr));
        $this->assertSame(12345, $other->fresh()->payrollProfile->basic_salary_minor);
        $this->assertSame('hourly', $this->user->fresh()->payrollProfile->pay_basis);
        $this->assertSame($this->hr->id, $this->user->fresh()->payrollProfile->template_snapshot['applied_by_user_id']);
        $this->assertSame(['applied' => 1, 'disabled' => 0], $svc->apply($t, [$this->user->id], $this->hr));
        $this->rejects(fn () => $t->update(['name' => 'Changed']), 'has been applied');
    }
}
