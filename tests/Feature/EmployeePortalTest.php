<?php

namespace Tests\Feature;

use App\Filament\Employee\Auth\Login;
use App\Filament\Employee\Pages\Attendance;
use App\Filament\Employee\Pages\ChangePassword;
use App\Filament\Employee\Pages\LeaveRequests;
use App\Filament\Employee\Widgets\PortalOverview;
use App\Filament\Tenant\Resources\EmployeeLeaves\Tables\EmployeeLeavesTable;
use App\Http\Middleware\InitializeTenancyByShortname;
use App\Livewire\Tenant\UserAttendanceCalendar;
use App\Models\AttendanceDay;
use App\Models\EmployeeLeave;
use App\Models\LeaveType;
use App\Models\ManualAttendanceEntry;
use App\Models\Organisation;
use App\Models\Schedule;
use App\Models\User;
use App\Services\Attendance\ApprovalService;
use App\Services\Attendance\AttendanceEntryService;
use App\Services\Attendance\ShiftAssignmentService;
use App\Services\Attendance\ShiftRuleService;
use App\Services\EmployeePortal\PortalAccess;
use App\Services\Leave\EmployeeLeaveService;
use App\Services\Leave\LeaveBalanceService;
use App\Services\Leave\LeaveBalanceSummaryService;
use App\Services\Leave\LeavePolicyService;
use App\Services\Support\TenantTimezone;
use Carbon\Carbon;
use Filament\Facades\Filament;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\URL;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;

class EmployeePortalTest extends TestCase
{
    private User $manager;

    private User $employee;

    protected function setUp(): void
    {
        parent::setUp();
        Artisan::call('migrate:fresh', ['--path' => database_path('migrations/tenant'), '--realpath' => true, '--force' => true]);
        config(['tenancy.bootstrappers' => [], 'tenancy.database.central_connection' => 'sqlite']);
        Schema::create('organisations', function (Blueprint $table) {
            $table->string('id')->primary();
            $table->string('shortname');
            $table->string('name');
            $table->json('data')->nullable();
            $table->timestamps();
        });
        Organisation::insert(['id' => 'portal-a', 'shortname' => 'portal', 'name' => 'Portal company']);
        tenancy()->initialize(Organisation::findOrFail('portal-a'));
        $this->manager = User::create(['pin' => 'manager', 'name' => 'HR', 'privilege' => User::HR_PRIVILEGE, 'email' => 'hr@test.local', 'password' => 'Admin-password1!']);
        $this->employee = User::create(['pin' => 'employee', 'name' => 'Employee One', 'email' => 'employee@test.local', 'device_password' => '123456', 'is_enabled' => true]);
        URL::defaults(['tenant' => 'portal']);
        Filament::setCurrentPanel(Filament::getPanel('employee'));
    }

    protected function tearDown(): void
    {
        tenancy()->end();
        parent::tearDown();
    }

    private function enable(bool $change = true): void
    {
        app(PortalAccess::class)->reset($this->manager, $this->employee);
        $this->employee->refresh();
        if ($change) {
            app(PortalAccess::class)->changePassword($this->employee, ['password' => 'Strong-Portal123!', 'password_confirmation' => 'Strong-Portal123!']);
        }
        $this->employee->refresh();
        $this->actingAs($this->employee, 'employee');
    }

    public function test_device_password_bootstrap_and_first_login_gate(): void
    {
        $this->enable(change: false);
        $this->assertTrue(Hash::check('123456', $this->employee->password));
        $this->assertTrue($this->employee->requires_password_change);
        $this->assertFalse($this->employee->canAccessPanel(Filament::getPanel('tenant')));
        $this->get('http://localhost/portal/employee')->assertRedirect('http://localhost/portal/employee/change-password');
        $this->get('http://localhost/portal/employee/change-password')->assertOk();
        Livewire::test(Attendance::class)->assertForbidden();
        Livewire::test(ChangePassword::class)->fillForm(['password' => 'Strong-Portal123!', 'password_confirmation' => 'Strong-Portal123!'])->call('save')->assertHasNoFormErrors();
        $this->assertFalse($this->employee->fresh()->requires_password_change);
        $this->get('http://localhost/portal/employee')->assertOk()->assertSee('Attendance reviews')->assertSee('start_url=%2Fportal%2Femployee', false);
    }

    public function test_native_employee_login_and_management_access_are_separate(): void
    {
        app(PortalAccess::class)->reset($this->manager, $this->employee);
        session()->put('url.intended', '/portal/admin');
        Livewire::test(Login::class)->fillForm(['email' => $this->employee->email, 'password' => '123456'])->call('authenticate')->assertHasNoFormErrors();
        $this->assertAuthenticatedAs($this->employee, 'employee');
        $this->assertGuest('web');
        $this->assertNull(session('url.intended'));
        $this->get('http://localhost/portal/admin')->assertRedirect();
    }

    public function test_short_device_password_and_weak_portal_password_are_rejected(): void
    {
        $this->employee->update(['device_password' => '12345']);
        try {
            app(PortalAccess::class)->reset($this->manager, $this->employee);
            $this->fail('Short password accepted');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('device_password', $e->errors());
        }
        $this->employee->update(['device_password' => '123456']);
        $this->enable(change: false);
        Livewire::test(ChangePassword::class)->fillForm(['password' => 'weakpass', 'password_confirmation' => 'weakpass'])->call('save')->assertHasFormErrors(['password']);
        $this->assertTrue($this->employee->fresh()->requires_password_change);
    }

    public function test_reset_requires_a_new_change_and_does_not_change_device_password(): void
    {
        $this->enable();
        app(PortalAccess::class)->reset($this->manager, $this->employee);
        $this->assertTrue($this->employee->fresh()->requires_password_change);
        $this->assertSame('123456', $this->employee->fresh()->device_password);
        $this->assertTrue(Hash::check('123456', $this->employee->fresh()->password));
        $this->get('http://localhost/portal/employee/attendance')->assertRedirect();
        $this->expectException(HttpException::class);
        app(PortalAccess::class)->reset($this->employee->fresh(), $this->employee);
    }

    public function test_table_filters_and_calendar_never_expose_other_employees(): void
    {
        $this->enable();
        $other = User::create(['pin' => 'other', 'name' => 'Other employee']);
        $mine = AttendanceDay::create(['user_id' => $this->employee->id, 'work_date' => today(), 'status' => 'absent']);
        $older = AttendanceDay::create(['user_id' => $this->employee->id, 'work_date' => today()->subMonths(2), 'status' => 'present']);
        $foreign = AttendanceDay::create(['user_id' => $other->id, 'work_date' => today(), 'status' => 'present']);
        $page = Livewire::test(Attendance::class)->assertCanSeeTableRecords([$mine])->assertCanNotSeeTableRecords([$foreign, $older]);
        $page->filterTable('date', ['from' => $older->work_date->toDateString(), 'to' => $older->work_date->toDateString()])->assertCanSeeTableRecords([$older])->assertCanNotSeeTableRecords([$mine, $foreign]);
        $page->set('tableRecordsPerPage', 'all');
        $this->assertContains($page->instance()->getTableRecordsPerPage(), [10, 25, 50]);
        Livewire::test(UserAttendanceCalendar::class, ['record' => $this->employee])->assertOk();
        Livewire::test(UserAttendanceCalendar::class, ['record' => $other])->assertForbidden();
    }

    public function test_employee_can_only_request_own_pending_leave_and_hr_can_review(): void
    {
        $this->enable();
        $type = LeaveType::create(['name' => 'Unpaid', 'code' => 'UNP', 'active' => true, 'is_paid' => false]);
        Livewire::test(LeaveRequests::class)->callAction('requestLeave', ['leave_date' => today()->toDateString(), 'leave_type_id' => $type->id, 'duration' => 'full', 'notes' => 'Personal'])->assertHasNoActionErrors();
        $leave = EmployeeLeave::firstOrFail();
        $this->assertSame($this->employee->id, $leave->user_id);
        $this->assertSame('pending', $leave->state);
        $this->assertNull($leave->reviewed_by_user_id);
        $this->actingAs($this->manager, 'web');
        Filament::setCurrentPanel(Filament::getPanel('tenant'));
        EmployeeLeavesTable::decide($leave, 'approved');
        $this->assertSame('approved', $leave->fresh()->state);
        try {
            app(EmployeeLeaveService::class)->save(null, ['user_id' => $this->employee->id, 'leave_type_id' => $type->id, 'leave_date' => today()->addDay(), 'duration' => 'full', 'state' => 'approved'], $this->employee);
            $this->fail('Self approval allowed');
        } catch (HttpException $e) {
            $this->assertSame(403, $e->getStatusCode());
        }
    }

    public function test_employee_cannot_submit_for_another_employee_or_request_without_password_change(): void
    {
        $this->enable();
        $other = User::create(['pin' => 'other', 'name' => 'Other']);
        try {
            app(AttendanceEntryService::class)->addOrCorrect($this->employee, ['user_id' => $other->id]);
            $this->fail('Foreign correction accepted');
        } catch (HttpException $e) {
            $this->assertSame(403, $e->getStatusCode());
        }
        $this->employee->update(['requires_password_change' => true]);
        $this->expectException(HttpException::class);
        app(AttendanceEntryService::class)->addOrCorrect($this->employee, ['user_id' => $this->employee->id]);
    }

    public function test_correction_requests_wait_for_hr_and_match_the_assigned_shift(): void
    {
        $this->enable();
        $date = today(TenantTimezone::get())->subDay()->toDateString();
        $shift = Schedule::create(['name' => 'Default day', 'type' => 'regular', 'valid_from' => '2026-01-01', 'status' => true,
            'rules' => ['start_time' => '09:00', 'end_time' => '17:00', 'weekdays' => [0, 1, 2, 3, 4, 5, 6], 'full_day_minutes' => 480, 'half_day_minutes' => 240,
                'earliest_arrival_minutes' => 60, 'checkout_cutoff_minutes' => 120, 'punch_method' => 'device']]);
        app(ShiftRuleService::class)->sync($shift);
        app(ShiftAssignmentService::class)->setDefault($shift, '2026-01-01');
        Livewire::test(Attendance::class)->callAction('requestCorrection', ['work_date' => $date, 'entry_mode' => 'manual', 'in_time' => '09:00', 'out_time' => '17:00', 'reason' => 'Device unavailable'])->assertHasNoActionErrors();
        $day = AttendanceDay::firstOrFail();
        $this->assertSame(0, $day->approved_worked_minutes);
        $approval = $day->approvals()->where('type', 'attendance')->where('state', 'pending')->firstOrFail();
        $widget = Livewire::test(PortalOverview::class)->instance();
        $stats = (new \ReflectionMethod($widget, 'getStats'))->invoke($widget);
        $this->assertSame(1, $stats[1]->getValue());
        app(ApprovalService::class)->decide($approval, $this->manager, true);
        $this->assertSame(480, $day->fresh()->approved_worked_minutes);
        $correction = app(AttendanceEntryService::class)->addOrCorrect($this->employee, ['user_id' => $this->employee->id, 'work_date' => $date, 'entry_mode' => 'correction', 'in_time' => '09:00', 'out_time' => '16:00', 'reason' => 'Actual departure']);
        $this->assertSame('pending', $correction->state);
        $this->assertSame(480, $day->fresh()->approved_worked_minutes);
        $this->expectException(ValidationException::class);
        app(AttendanceEntryService::class)->addOrCorrect($this->employee, ['user_id' => $this->employee->id, 'work_date' => $date, 'entry_mode' => 'correction', 'in_time' => '22:00', 'out_time' => '06:00', 'reason' => 'Wrong shift']);
    }

    public function test_paid_leave_reserves_the_available_balance_until_hr_decides(): void
    {
        $this->enable();
        $date = today(TenantTimezone::get());
        $type = LeaveType::create(['name' => 'Annual', 'code' => 'ANN', 'active' => true, 'is_paid' => true]);
        app(LeaveBalanceService::class)->opening($this->employee, $type, 2, $date->copy()->subDay(), $date->copy()->addMonth(), 'Opening', $this->manager, 'portal-opening');
        $this->assertArrayNotHasKey($type->id, LeaveRequests::availableTypes($date->toDateString()));
        // Paid bookings still need an applicable policy, even when an opening balance exists.
        try {
            app(EmployeeLeaveService::class)->save(null, ['user_id' => $this->employee->id, 'leave_type_id' => $type->id, 'leave_date' => $date->toDateString(), 'duration' => 'full', 'state' => 'pending'], $this->employee);
            $this->fail('Paid request without policy accepted');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('leave_type_id', $e->errors());
        }
        $this->assertSame(0, EmployeeLeave::count());
        app(LeavePolicyService::class)->save(null, [
            'name' => 'Annual policy', 'leave_type_id' => $type->id, 'tenant_default' => true, 'effective_from' => $date->toDateString(),
            'reason' => 'Policy reviewed', 'rules' => array_replace(LeavePolicyService::defaults(), ['grant_half_units' => 0]),
        ], $this->manager);
        $this->assertArrayHasKey($type->id, LeaveRequests::availableTypes($date->toDateString()));
        $leave = app(EmployeeLeaveService::class)->save(null, ['user_id' => $this->employee->id, 'leave_type_id' => $type->id, 'leave_date' => $date->toDateString(), 'duration' => 'full', 'state' => 'pending'], $this->employee);
        $this->assertSame(2, (int) DB::table('leave_allocations')->where('state', 'reserved')->sum('half_units'));
        $this->assertSame(0, app(LeaveBalanceSummaryService::class)->get($this->employee, $type->id, $date)['available_half_units']);
        $this->actingAs($this->manager, 'web');
        EmployeeLeavesTable::decide($leave, 'rejected');
        $this->assertSame(2, app(LeaveBalanceSummaryService::class)->get($this->employee, $type->id, $date)['available_half_units']);

        $empty = LeaveType::create(['name' => 'No balance', 'code' => 'EMPTY', 'active' => true, 'is_paid' => true]);
        $this->assertArrayNotHasKey($empty->id, LeaveRequests::availableTypes($date->toDateString()));
    }

    public function test_disabled_employee_cannot_access_any_portal_page(): void
    {
        $this->enable();
        $this->employee->update(['is_enabled' => false]);
        Livewire::test(Attendance::class)->assertForbidden();
        Livewire::test(LeaveRequests::class)->assertForbidden();
        $this->get('http://localhost/portal/employee/calendar')->assertForbidden();
    }

    public function test_night_request_uses_next_day_checkout_without_a_shift_selector(): void
    {
        $this->enable();
        $date = today(TenantTimezone::get())->subDay()->toDateString();
        $shift = Schedule::create(['name' => 'Night', 'type' => 'regular', 'valid_from' => '2026-01-01', 'status' => true,
            'rules' => ['start_time' => '22:00', 'end_time' => '06:00', 'end_day_offset' => 1, 'weekdays' => [0, 1, 2, 3, 4, 5, 6], 'full_day_minutes' => 480, 'half_day_minutes' => 240,
                'earliest_arrival_minutes' => 60, 'checkout_cutoff_minutes' => 120, 'punch_method' => 'device']]);
        app(ShiftRuleService::class)->sync($shift);
        app(ShiftAssignmentService::class)->setDefault($shift, '2026-01-01');
        Livewire::test(Attendance::class)->callAction('requestCorrection', ['work_date' => $date, 'entry_mode' => 'manual', 'in_time' => '22:00', 'out_time' => '06:00', 'reason' => 'Missing punches'])->assertHasNoActionErrors();
        $entry = ManualAttendanceEntry::firstOrFail();
        $this->assertSame($date, $entry->in_at->toDateString());
        $this->assertSame(Carbon::parse($date)->addDay()->toDateString(), $entry->out_at->toDateString());
        $this->assertSame(0, AttendanceDay::firstOrFail()->approved_worked_minutes);
    }

    public function test_employee_remember_cookie_is_scoped_to_its_tenant_and_separate_from_management(): void
    {
        $this->enable();
        $guard = Auth::guard('employee');
        $guard->login($this->employee, remember: true);
        $alphaLogin = $guard->getName();
        $cookieName = $guard->getRecallerName();
        $cookieValue = collect(app('cookie')->getQueuedCookies())->first(fn ($cookie) => $cookie->getName() === $cookieName)->getValue();
        $this->assertNotSame($alphaLogin, Auth::guard('web')->getName());
        Organisation::insert(['id' => 'portal-b', 'shortname' => 'other', 'name' => 'Other company']);
        Route::get('/api/employee-session-probe/{tenant}', fn () => response()->json(['id' => auth('employee')->id()]))
            ->middleware(['web', InitializeTenancyByShortname::class]);
        Auth::forgetGuards();
        $this->withCookie($cookieName, $cookieValue)->get('http://localhost/api/employee-session-probe/other')->assertOk()->assertJson(['id' => null]);
        $this->assertNotSame($alphaLogin, auth('employee')->getName());
    }

    public function test_password_changes_after_first_login_require_the_current_password(): void
    {
        $this->enable();
        Livewire::test(ChangePassword::class)->fillForm(['password' => 'Another-Password123!', 'password_confirmation' => 'Another-Password123!'])
            ->call('save')->assertHasFormErrors(['current_password']);
        $this->assertTrue(Hash::check('Strong-Portal123!', $this->employee->fresh()->password));
    }
}
