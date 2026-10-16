<?php

namespace Tests\Feature;

use App\Filament\Tenant\Resources\EmployeeLeaves\Pages\ListEmployeeLeaves;
use App\Filament\Tenant\Resources\LeavePolicies\Pages\CreateLeavePolicy;
use App\Filament\Tenant\Resources\PayrollTemplates\Pages\ManagePayrollTemplates;
use App\Filament\Tenant\Resources\TaskGroups\Pages\CreateTaskGroup;
use App\Models\EmployeeLeave;
use App\Models\LeavePolicy;
use App\Models\LeaveType;
use App\Models\PayrollTemplate;
use App\Models\Schedule;
use App\Models\TaskGroup;
use App\Models\User;
use App\Services\Attendance\ShiftAssignmentService;
use App\Services\Attendance\ShiftRuleService;
use App\Services\Leave\LeavePolicyService;
use Carbon\Carbon;
use Filament\Facades\Filament;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\URL;
use Livewire\Livewire;
use Tests\TestCase;

class BetaOrganisationActionsTest extends TestCase
{
    private User $hr;

    private User $employee;

    private LeaveType $type;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-10-14');
        Artisan::call('migrate:fresh', ['--path' => database_path('migrations/tenant'), '--realpath' => true, '--force' => true]);
        $this->hr = User::create(['name' => 'HR', 'pin' => 'hr', 'privilege' => 14, 'is_system_account' => true]);
        $this->employee = User::create(['name' => 'Employee', 'pin' => 'e']);
        $this->type = LeaveType::create(['name' => 'Paid leave', 'code' => 'PL', 'is_paid' => true, 'active' => true]);
        Filament::setCurrentPanel(Filament::getPanel('tenant'));
        URL::defaults(['tenant' => 'test']);
        $this->actingAs($this->hr);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_leave_range_wizard_previews_then_saves_daily_records(): void
    {
        $s = Schedule::create(['name' => 'Weekdays', 'type' => 'regular', 'valid_from' => '2026-08-01', 'status' => true, 'rules' => ['start_time' => '09:00', 'end_time' => '17:00', 'weekdays' => [1, 2, 3, 4, 5], 'half_day_minutes' => 240, 'full_day_minutes' => 480]]);
        app(ShiftRuleService::class)->sync($s);
        app(ShiftAssignmentService::class)->setDefault($s, '2026-08-01');
        app(LeavePolicyService::class)->save(null, ['name' => 'Paid', 'leave_type_id' => $this->type->id, 'tenant_default' => true, 'effective_from' => '2026-08-01', 'reason' => 'HR settings', 'rules' => LeavePolicyService::defaults()], $this->hr);
        $page = Livewire::test(ListEmployeeLeaves::class)->mountAction('requestRange')->fillForm(['user_id' => $this->employee->id, 'leave_type_id' => $this->type->id, 'starts_on' => '2026-08-07', 'ends_on' => '2026-08-10', 'duration' => 'full', 'state' => 'approved'])->goToNextWizardStep()->assertHasNoErrors();
        $this->assertSame(0, EmployeeLeave::count());
        $this->assertStringContainsString('off', $page->get('rangePreview'));
        $this->assertNotEmpty($page->get('rangeSignature'));
        $page->callMountedAction()->assertHasNoErrors()->assertNotified('2 leave dates saved');
        $this->assertSame(2, EmployeeLeave::count());
    }

    public function test_policy_form_saves_multiple_resets_and_work_month_expiry(): void
    {
        Livewire::test(CreateLeavePolicy::class)->fillForm(['name' => 'Outreach', 'leave_type_id' => $this->type->id, 'tenant_default' => true, 'effective_from' => '2026-08-01', 'reason' => 'Company calendar', 'rules.expiry_method' => 'resets', 'rules.reset_dates' => [['date' => '2026-06-01'], ['date' => '2026-12-31']], 'rules.comp_enabled' => true, 'rules.comp_reward' => 'leave', 'rules.comp_expiry_mode' => 'work_month_end'])->call('create')->assertHasNoFormErrors();
        $rules = LeavePolicy::firstOrFail()->revisions()->first()->rules;
        $this->assertCount(2, $rules['reset_dates']);
        $this->assertSame('work_month_end', $rules['comp_expiry_mode']);
    }

    public function test_task_group_form_and_employee_membership_are_wired(): void
    {
        Livewire::test(CreateTaskGroup::class)->fillForm(['name' => 'Role holiday'])->call('create')->assertHasNoFormErrors();
        $group = TaskGroup::firstOrFail();
        $this->employee->taskGroups()->sync([$group->id]);
        $this->assertTrue($this->employee->fresh()->taskGroups()->whereKey($group->id)->exists());
    }

    public function test_payroll_template_create_and_bulk_apply_actions_are_wired(): void
    {
        $this->employee->payrollProfile()->create(['payroll_enabled' => true, 'pay_basis' => 'monthly', 'currency' => 'INR', 'basic_salary_minor' => 900000, 'effective_from' => '2026-10-01']);
        $page = Livewire::test(ManagePayrollTemplates::class)->callAction('create', data: ['name' => 'Hourly branch', 'effective_from' => '2026-11-01', 'settings.pay_basis' => 'hourly', 'settings.currency' => 'INR', 'settings.basic_salary_minor' => 100, 'settings.overtime_hourly_rate_minor' => 200, 'settings.housing_allowance_minor' => 0, 'settings.transport_allowance_minor' => 0, 'settings.other_allowance_minor' => 0, 'settings.fixed_deduction_minor' => 0])->assertHasNoErrors();
        $t = PayrollTemplate::firstOrFail();
        $this->assertSame(10000, $t->settings['basic_salary_minor']);
        $page->callTableAction('apply', $t, ['user_ids' => [$this->employee->id]])->assertHasNoErrors();
        $this->assertSame(10000, $this->employee->fresh()->payrollProfile->basic_salary_minor);
    }

    public function test_new_admin_resources_reject_employee_access(): void
    {
        $this->actingAs($this->employee);
        Livewire::test(ManagePayrollTemplates::class)->assertForbidden();
        Livewire::test(CreateTaskGroup::class)->assertForbidden();
    }
}
