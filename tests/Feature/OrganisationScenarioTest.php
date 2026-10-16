<?php

namespace Tests\Feature;

use App\Models\AttendanceLog;
use App\Models\Device;
use App\Models\Holiday;
use App\Models\LeaveCreditLot;
use App\Models\LeaveType;
use App\Models\SalarySlip;
use App\Models\Schedule;
use App\Models\User;
use App\Services\Attendance\ApprovalService;
use App\Services\Attendance\AttendanceCalculationService;
use App\Services\Attendance\ShiftAssignmentService;
use App\Services\Attendance\ShiftRuleService;
use App\Services\Leave\EmployeeLeaveService;
use App\Services\Leave\LeaveBalanceService;
use App\Services\Leave\LeavePolicyService;
use App\Services\Payroll\SalarySlipService;
use Carbon\Carbon;
use Carbon\CarbonPeriod;
use Illuminate\Support\Facades\Artisan;
use Tests\TestCase;

class OrganisationScenarioTest extends TestCase
{
    private User $hr;

    private Device $device;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2027-01-15 12:00:00');
        Artisan::call('migrate:fresh', ['--path' => database_path('migrations/tenant'), '--realpath' => true, '--force' => true]);
        $this->hr = User::create(['name' => 'Scenario HR', 'pin' => 'hr', 'privilege' => 14, 'is_system_account' => true]);
        $this->device = Device::create(['serial_number' => 'SCENARIO']);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function shift(array $weekdays, bool $night = false): Schedule
    {
        $s = Schedule::create(['name' => $night ? 'Night' : 'Day', 'type' => 'regular', 'valid_from' => '2026-01-01', 'status' => true, 'rules' => [
            'start_time' => $night ? '22:00' : '09:00', 'end_time' => $night ? '06:00' : '17:00', 'end_day_offset' => $night ? 1 : 0,
            'weekdays' => $weekdays, 'full_day_minutes' => 480, 'half_day_minutes' => 240, 'punch_method' => 'device',
            'earliest_arrival_minutes' => 60, 'checkout_cutoff_minutes' => 180, 'overtime_basis' => 'after_required_hours', 'overtime_minimum_minutes' => 30]]);
        app(ShiftRuleService::class)->sync($s);

        return $s;
    }

    private function employee(string $pin, Schedule $shift, string $basis = 'monthly', int $ot = 0): User
    {
        $u = User::create(['name' => $pin, 'pin' => $pin]);
        $u->payrollProfile()->create(['payroll_enabled' => true, 'pay_basis' => $basis, 'basic_salary_minor' => $basis === 'hourly' ? 10_000 : 3_000_000, 'overtime_hourly_rate_minor' => $ot, 'currency' => 'INR', 'effective_from' => '2026-01-01']);
        app(ShiftAssignmentService::class)->assign('user', $u, ['schedule_ids' => [$shift->id], 'effective_from' => '2026-01-01']);

        return $u;
    }

    private function policy(LeaveType $type, int $units, bool $default = true, array $extra = [])
    {
        return app(LeavePolicyService::class)->save(null, ['name' => 'Scenario entitlement '.$units, 'leave_type_id' => $type->id, 'tenant_default' => $default, 'effective_from' => '2026-01-01', 'reason' => 'Scenario verification', 'rules' => array_replace(LeavePolicyService::defaults(), [
            'grant_half_units' => $units, 'prorate_join_exit' => false, 'carry_cap_half_units' => 40, 'balance_cap_half_units' => 40, 'fixed_expiry_month' => 3, 'fixed_expiry_day' => 31, 'allow_unpaid_excess' => true, 'unpaid_excess_deduction_method' => 'basic_salary_31_day_rate'], $extra)], $this->hr);
    }

    private function recordLeave(User $u, LeaveType $type, string $date): void
    {
        app(EmployeeLeaveService::class)->save(null, ['user_id' => $u->id, 'leave_type_id' => $type->id, 'leave_date' => $date, 'duration' => 'full', 'state' => 'approved'], $this->hr);
    }

    private function month(User $u, string $month, bool $night = false, array $leaveDates = [], ?string $otDate = null): SalarySlip
    {
        $calc = app(AttendanceCalculationService::class);
        foreach (CarbonPeriod::create($month, Carbon::parse($month)->endOfMonth()) as $date) {
            $day = $calc->calculate($u, $date);
            if (! in_array($day->status, ['off', 'holiday']) && ! in_array($date->toDateString(), $leaveDates)) {
                $in = $date->copy()->setTime($night ? 22 : 9, 0);
                $out = $in->copy()->addHours($date->toDateString() === $otDate ? 9 : 8);
                AttendanceLog::create(['device_id' => $this->device->id, 'pin' => $u->pin, 'punched_at' => $in, 'status' => 0]);
                AttendanceLog::create(['device_id' => $this->device->id, 'pin' => $u->pin, 'punched_at' => $out, 'status' => 1]);
                $day = $calc->calculate($u, $date);
                if ($date->toDateString() === $otDate) {
                    $ot = $day->approvals()->where('type', 'overtime')->where('state', 'pending')->firstOrFail();
                    app(ApprovalService::class)->decide($ot, $this->hr, true);
                    $this->assertSame($date->toDateString(), $day->work_date->toDateString());
                    $this->assertSame(60, $day->fresh()->approved_overtime_minutes);
                }
            }
        }

        return app(SalarySlipService::class)->generate($u, Carbon::parse($month));
    }

    public function test_secumax_three_employees_three_months_actual_calendar_deductions(): void
    {
        $shift = $this->shift([1, 2, 3, 4, 5]);
        $shift->update(['rules' => array_replace($shift->rules, ['overtime_enabled' => false])]);
        app(ShiftRuleService::class)->sync($shift);
        $type = LeaveType::create(['name' => 'Unpaid', 'code' => 'UP', 'is_paid' => false, 'active' => true, 'deduction_method' => 'basic_salary_31_day_rate']);
        foreach (['SEC-1', 'SEC-2', 'SEC-3'] as $pin) {
            $u = $this->employee($pin, $shift);
            foreach (['2026-01-01', '2026-02-01', '2026-03-01'] as $month) {
                $date = Carbon::parse($month)->addDays(4);
                while ($date->isWeekend()) {
                    $date->addDay();
                }
                $this->recordLeave($u, $type, $date->toDateString());
                $slip = $this->month($u, $month, leaveDates: [$date->toDateString()]);
                $cut = (int) round(3_000_000 / Carbon::parse($month)->daysInMonth);
                $this->assertSame($cut, $slip->leave_deduction_minor);
                $this->assertSame(3_000_000 - $cut, $slip->net_pay_minor);
                $this->assertSame(0, $slip->paid_leave_half_units);
                $this->assertSame(0, $slip->overtime_pay_minor);
                $this->assertSame(CarbonPeriod::create($month, Carbon::parse($month)->endOfMonth())->filter(fn ($d) => ! $d->isWeekend())->count(), $slip->scheduled_days);
            }
        }
        $this->assertSame(9, SalarySlip::count());
    }

    public function test_nova_three_employees_three_months_entitlement_night_ot_and_hourly_pay(): void
    {
        $day = $this->shift([0, 1, 2, 3, 4, 5, 6]);
        $night = $this->shift([0, 1, 2, 3, 4, 5, 6], true);
        $type = LeaveType::create(['name' => 'Paid leave', 'code' => 'PL', 'is_paid' => true, 'active' => true]);
        $this->policy($type, 4);
        $manager = $this->policy($type, 6, false);
        Holiday::create(['name' => 'Company holiday', 'starts_on' => '2026-01-26', 'ends_on' => '2026-01-26', 'recurrence' => 'none', 'active' => true]);
        foreach ([['NOVA-STAFF', $day, 'monthly', false, 12], ['NOVA-MANAGER', $night, 'monthly', true, 18], ['NOVA-HOURLY', $day, 'hourly', false, 12]] as [$pin,$shift,$basis,$overnight,$coverage]) {
            $u = $this->employee($pin, $shift, $basis, 20_000);
            if ($overnight) {
                app(LeavePolicyService::class)->assign($manager, [$u->id], Carbon::parse('2026-01-01'), null, $this->hr);
            }
            foreach (['2026-01-01', '2026-02-01', '2026-03-01'] as $month) {
                app(LeaveBalanceService::class)->ensureGrants($u, $type->id, Carbon::parse($month));
                $dates = [];
                if ($month === '2026-03-01') {
                    foreach (range(10, 19) as $n) {
                        $dates[] = '2026-03-'.$n;
                        $this->recordLeave($u, $type, end($dates));
                    }
                }
                $slip = $this->month($u, $month, $overnight, $dates, Carbon::parse($month)->addDay()->toDateString());
                $this->assertSame(20_000, $slip->overtime_pay_minor);
                if ($dates) {
                    $this->assertSame($coverage, $slip->paid_leave_half_units);
                    $this->assertSame(20 - $coverage, $slip->unpaid_leave_half_units);
                    if ($basis === 'monthly') {
                        $this->assertSame((int) ((20 - $coverage) / 2) * (int) round(3_000_000 / 31), $slip->leave_deduction_minor, 'Calendar deduction');
                    }
                }
                if ($basis === 'hourly') {
                    $this->assertSame($slip->full_days * 8 * 10_000, $slip->basic_salary_minor);
                    $this->assertSame($dates ? 6 * 8 * 10_000 : 0, $slip->other_allowance_minor);
                }
            }
            Carbon::setTestNow('2026-04-01');
            $this->assertSame(0, LeaveCreditLot::where('user_id', $u->id)->whereDate('expires_on', '>', '2026-03-31')->whereDate('available_on', '<', '2026-04-01')->count());
            Carbon::setTestNow('2027-01-15');
        }
        $this->assertSame(9, SalarySlip::count());
    }

    public function test_outreach_three_employees_three_months_one_paid_day_then_unpaid(): void
    {
        $shift = $this->shift([1, 2, 3, 4, 5, 6]);
        $type = LeaveType::create(['name' => 'Paid leave', 'code' => 'PL', 'is_paid' => true, 'active' => true]);
        $this->policy($type, 2, true, ['expiry_method' => 'resets', 'reset_dates' => [['date' => '2026-06-01'], ['date' => '2026-12-31']], 'leave_period_from' => '2026-04-01', 'leave_period_to' => '2026-06-30']);
        foreach (['OUTREACH-1', 'OUTREACH-2', 'OUTREACH-3'] as $pin) {
            $u = $this->employee($pin, $shift);
            foreach (['2026-04-01', '2026-05-01', '2026-06-01'] as $month) {
                $date = Carbon::parse($month)->addDays(4);
                if ($date->dayOfWeek === 0) {
                    $date->addDay();
                }
                $dates = [$date->toDateString(), $date->copy()->addDay()->toDateString()];
                foreach ($dates as $d) {
                    $this->recordLeave($u, $type, $d);
                }
                $slip = $this->month($u, $month, leaveDates: $dates);
                $this->assertSame(2, $slip->paid_leave_half_units);
                $this->assertSame(2, $slip->unpaid_leave_half_units);
                $this->assertSame((int) round(3_000_000 / Carbon::parse($month)->daysInMonth), $slip->leave_deduction_minor);
                $this->assertSame(0, $slip->overtime_pay_minor);
                $this->assertSame(3_000_000, $slip->gross_pay_minor);
            }
        }
        $this->assertSame(9, SalarySlip::count());
    }

    public function test_nova_seasonal_shift_override_ends_without_reassigning_three_employees(): void
    {
        $normal = $this->shift([0, 1, 2, 3, 4, 5, 6]);
        app(ShiftAssignmentService::class)->setDefault($normal, '2026-01-01');
        $season = $this->shift([0, 1, 2, 3, 4, 5, 6]);
        $season->update(['name' => 'Seasonal', 'rules' => array_replace($season->rules, ['start_time' => '08:00', 'end_time' => '16:00'])]);
        app(ShiftRuleService::class)->sync($season);
        foreach (['SEASON-1', 'SEASON-2', 'SEASON-3'] as $pin) {
            $u = User::create(['name' => $pin, 'pin' => $pin]);
            app(ShiftAssignmentService::class)->assign('user', $u, ['schedule_ids' => [$season->id], 'effective_from' => '2026-02-01', 'effective_to' => '2026-02-28']);
            foreach (['2026-01-15', '2026-02-15', '2026-03-15'] as $date) {
                $hour = $date === '2026-02-15' ? 8 : 9;
                $in = Carbon::parse($date)->setTime($hour, 0);
                AttendanceLog::create(['device_id' => $this->device->id, 'pin' => $pin, 'punched_at' => $in, 'status' => 0]);
                AttendanceLog::create(['device_id' => $this->device->id, 'pin' => $pin, 'punched_at' => $in->copy()->addHours(8), 'status' => 1]);
                $day = app(AttendanceCalculationService::class)->calculate($u, Carbon::parse($date));
                $this->assertSame('present', $day->status);
                $this->assertSame(480, $day->approved_worked_minutes);
                $this->assertSame($hour === 8 ? $season->id : $normal->id, $day->occurrences->first()->schedule_id);
            }
        }
    }
}
