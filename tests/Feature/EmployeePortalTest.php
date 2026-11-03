<?php

namespace Tests\Feature;

use App\Filament\Employee\Auth\Login;
use App\Filament\Employee\Pages\Attendance;
use App\Filament\Employee\Pages\ChangePassword;
use App\Filament\Employee\Pages\LeaveRequests;
use App\Filament\Employee\Pages\SalarySlips;
use App\Filament\Employee\Widgets\LocationPunch;
use App\Filament\Employee\Widgets\PortalOverview;
use App\Filament\Tenant\Pages\OrganisationSettings;
use App\Filament\Tenant\Resources\EmployeeLeaves\Tables\EmployeeLeavesTable;
use App\Filament\Tenant\Resources\PortalPunches\Pages\ManagePortalPunches;
use App\Filament\Tenant\Resources\WorkLocations\Pages\ManageWorkLocations;
use App\Http\Middleware\InitializeTenancyByShortname;
use App\Jobs\RefreshAttendanceDay;
use App\Livewire\Tenant\UserAttendanceCalendar;
use App\Models\AttendanceDay;
use App\Models\AttendanceLog;
use App\Models\Branch;
use App\Models\EmployeeLeave;
use App\Models\EmployeePortalSetting;
use App\Models\LeaveType;
use App\Models\ManualAttendanceEntry;
use App\Models\Organisation;
use App\Models\PortalPunch;
use App\Models\SalarySlip;
use App\Models\Schedule;
use App\Models\User;
use App\Models\WorkLocation;
use App\Services\Attendance\ApprovalService;
use App\Services\Attendance\AttendanceCalculationService;
use App\Services\Attendance\AttendanceEntryService;
use App\Services\Attendance\ShiftAssignmentService;
use App\Services\Attendance\ShiftRuleService;
use App\Services\EmployeePortal\GeofencedPunchService;
use App\Services\EmployeePortal\PortalAccess;
use App\Services\EmployeePortal\PunchApprovalService;
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
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Str;
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
        Storage::fake('local');
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
        $this->get('http://localhost/portal/ess')->assertRedirect('http://localhost/portal/ess/change-password');
        $this->get('http://localhost/portal/ess/change-password')->assertOk();
        Livewire::test(Attendance::class)->assertForbidden();
        Livewire::test(ChangePassword::class)->fillForm(['password' => 'Strong-Portal123!', 'password_confirmation' => 'Strong-Portal123!'])->call('save')->assertHasNoFormErrors();
        $this->assertFalse($this->employee->fresh()->requires_password_change);
        $this->get('http://localhost/portal/ess')->assertOk()->assertSee('Attendance reviews')->assertSee('start_url=%2Fportal%2Fess', false);
    }

    public function test_native_employee_login_and_management_access_are_separate(): void
    {
        $this->assertFalse((bool) $this->employee->portal_enabled);
        session()->put('url.intended', '/portal/admin');
        Livewire::test(Login::class)->fillForm(['email' => $this->employee->email, 'password' => '123456'])->call('authenticate')->assertHasNoFormErrors();
        $this->assertAuthenticatedAs($this->employee, 'employee');
        $this->assertGuest('web');
        $this->assertTrue($this->employee->fresh()->requires_password_change);
        $this->assertNull(session('url.intended'));
        $this->get('http://localhost/portal/admin')->assertRedirect();
        $this->get('http://localhost/portal/employee/login')->assertRedirect('http://localhost/portal/ess/login');
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
        $this->get('http://localhost/portal/ess/attendance')->assertRedirect();
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
        $page = Livewire::test(Attendance::class)->assertCanSeeTableRecords([$mine, $older])->assertCanNotSeeTableRecords([$foreign]);
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
        $this->get('http://localhost/portal/ess/calendar')->assertForbidden();
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

    public function test_automatic_login_rejects_invalid_credentials_and_device_fallback_after_password_change(): void
    {
        $provider = Auth::guard('employee')->getProvider();
        $this->assertFalse($provider->validateCredentials($this->employee, ['password' => 'wrong-password']));
        $this->assertFalse((bool) $this->employee->fresh()->portal_enabled);
        $this->employee->update(['is_enabled' => false]);
        $this->assertFalse($provider->validateCredentials($this->employee, ['password' => '123456']));
        $this->employee->update(['is_enabled' => true, 'device_password' => '12345']);
        $this->assertFalse($provider->validateCredentials($this->employee, ['password' => '12345']));
        $this->employee->update(['device_password' => '123456']);
        $this->assertTrue($provider->validateCredentials($this->employee, ['password' => '123456']));
        $this->assertSame('123456', $this->employee->fresh()->device_password);
        app(PortalAccess::class)->changePassword($this->employee, ['password' => 'Strong-Portal123!', 'password_confirmation' => 'Strong-Portal123!']);
        $this->employee->refresh();
        $this->assertFalse($provider->validateCredentials($this->employee, ['password' => '123456']));
        $this->assertTrue($provider->validateCredentials($this->employee, ['password' => 'Strong-Portal123!']));
        $this->assertFalse($provider->validateCredentials($this->manager, ['password' => 'Admin-password1!']));
    }

    public function test_attendance_defaults_to_all_history_except_holidays_and_weekly_offs(): void
    {
        $this->enable();
        $old = AttendanceDay::create(['user_id' => $this->employee->id, 'work_date' => today()->subYears(2), 'status' => 'present']);
        $off = AttendanceDay::create(['user_id' => $this->employee->id, 'work_date' => today(), 'status' => 'off']);
        $holiday = AttendanceDay::create(['user_id' => $this->employee->id, 'work_date' => today()->subDay(), 'status' => 'holiday']);
        Livewire::test(Attendance::class)->assertCanSeeTableRecords([$old])->assertCanNotSeeTableRecords([$off, $holiday]);
    }

    public function test_monthly_dashboard_counts_match_the_calendar_and_ignore_other_periods_and_employees(): void
    {
        $this->enable();
        Carbon::setTestNow('2026-11-20 12:00:00');
        try {
            foreach (['present', 'half_day', 'absent', 'off', 'holiday'] as $index => $status) {
                AttendanceDay::create(['user_id' => $this->employee->id, 'work_date' => '2026-11-'.(10 + $index), 'status' => $status]);
            }
            $missingCheckout = AttendanceDay::create(['user_id' => $this->employee->id, 'work_date' => '2026-11-15', 'status' => 'present', 'calculation_revision' => 1]);
            $missingCheckout->approvals()->create(['type' => 'attendance', 'state' => 'pending', 'calculation_revision' => 1, 'proposal' => ['reason' => 'missing_checkout'], 'idempotency_key' => 'monthly-missing']);
            AttendanceDay::create(['user_id' => $this->employee->id, 'work_date' => '2026-10-15', 'status' => 'present']);
            AttendanceDay::create(['user_id' => $this->employee->id, 'work_date' => '2026-11-25', 'status' => 'present']);
            AttendanceDay::create(['user_id' => $this->manager->id, 'work_date' => '2026-11-15', 'status' => 'present']);
            $widget = Livewire::test(PortalOverview::class)->instance();
            $stats = collect((new \ReflectionMethod($widget, 'getStats'))->invoke($widget))->keyBy(fn ($stat) => $stat->getLabel());
            $this->assertSame(1, $stats['Present']->getValue());
            $this->assertSame(2, $stats['Absent']->getValue());
            $this->assertSame(1, $stats['Half days']->getValue());
        } finally {
            Carbon::setTestNow();
        }
    }

    public function test_salary_history_and_details_only_include_own_approved_and_paid_slips(): void
    {
        $this->enable();
        $slips = [];
        foreach (['draft', 'reviewed', 'approved', 'paid'] as $index => $status) {
            $slips[$status] = SalarySlip::create(['user_id' => $this->employee->id, 'period_start' => '2026-0'.($index + 1).'-01', 'period_end' => '2026-0'.($index + 1).'-28',
                'status' => $status, 'currency' => 'INR', 'net_pay_minor' => 123456, 'calculation_snapshot' => [], 'calculated_at' => now()]);
        }
        $foreign = $slips['approved']->replicate()->fill(['user_id' => $this->manager->id]);
        $foreign->save();
        $page = Livewire::test(SalarySlips::class)->assertCanSeeTableRecords([$slips['approved'], $slips['paid']])
            ->assertCanNotSeeTableRecords([$slips['draft'], $slips['reviewed'], $foreign]);
        $page->mountTableAction('view', $slips['approved'])->assertActionMounted()->assertMountedActionModalSee('Payslip summary')->assertMountedActionModalSee('1,234.56');
        $page->unmountAction();
        $page->callTableAction('download', $slips['approved'])->assertFileDownloaded('salary-slips-2026-03.pdf');
        $page->mountTableAction('view', $foreign)->assertActionNotMounted();
        $page->mountTableAction('view', $slips['draft'])->assertActionNotMounted();
        $slips['approved']->update(['status' => 'draft']);
        $page->mountTableAction('view', $slips['approved'])->assertActionNotMounted();
        $this->get('http://localhost/portal/ess/salary-slips')->assertOk();
    }

    private function fence(): WorkLocation
    {
        EmployeePortalSetting::whereKey(1)->update(['geofencing_enabled' => true]);

        return WorkLocation::create(['name' => 'Office', 'latitude' => 11.2588, 'longitude' => 75.7804, 'radius_meters' => 100, 'is_active' => true]);
    }

    private function gps(WorkLocation $location, string $kind = 'in'): array
    {
        $image = imagecreatetruecolor(320, 240);
        ob_start();
        imagejpeg($image);
        $photo = 'data:image/jpeg;base64,'.base64_encode(ob_get_clean());
        imagedestroy($image);

        return ['photo' => $photo, 'location_id' => $location->id, 'latitude' => $location->latitude, 'longitude' => $location->longitude,
            'accuracy' => 5, 'captured_at' => now()->getTimestampMs(), 'kind' => $kind, 'request_id' => (string) Str::uuid()];
    }

    public function test_geofenced_punch_uses_server_identity_and_time_and_retry_is_idempotent(): void
    {
        $this->enable();
        Queue::fake();
        $location = $this->fence();
        $data = $this->gps($location) + ['user_id' => $this->manager->id, 'pin' => 'forged', 'punched_at' => '2020-01-01 00:00:00'];
        $service = app(GeofencedPunchService::class);
        $log = $service->record($data);
        $this->assertSame($this->employee->id, $log->user_id);
        $this->assertSame($this->employee->pin, $log->evidence['pin']);
        $this->assertSame('pending', $log->state);
        $this->assertSame(0, AttendanceLog::count());
        $this->assertSame('employee_portal', $log->evidence['source']);
        $this->assertSame('Office', $log->evidence['location_name']);
        $this->assertEqualsWithDelta(now()->timestamp, $log->punched_at->timestamp, 1);
        $this->assertSame($log->id, $service->record($data)->id);
        $this->assertSame(1, PortalPunch::count());
        Queue::assertNothingPushed();
        $location->update(['latitude' => 12]);
        $this->assertSame(11.2588, $log->fresh()->evidence['fence_latitude']);
        try {
            (require database_path('migrations/tenant/2026_11_03_112930_create_portal_punches.php'))->down();
            $this->fail('Rollback removed portal evidence');
        } catch (\RuntimeException $exception) {
            $this->assertStringContainsString('Punch requests exist', $exception->getMessage());
        }
        try {
            $this->employee->delete();
            $this->fail('Employee with portal evidence was deleted');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('employee', $exception->errors());
        }
        $this->expectException(\LogicException::class);
        $log->update(['kind' => 'out']);
    }

    public function test_geofence_rejects_outside_inaccurate_stale_or_invalid_location(): void
    {
        $this->enable();
        Queue::fake();
        $location = $this->fence();
        foreach ([['latitude' => 12], ['accuracy' => 101], ['accuracy' => 0], ['latitude' => 91],
            ['captured_at' => now()->subMinutes(3)->getTimestampMs()], ['location_id' => 999], ['request_id' => 'not-a-uuid']] as $change) {
            try {
                app(GeofencedPunchService::class)->record(array_replace($this->gps($location), $change));
                $this->fail('Invalid GPS evidence was accepted');
            } catch (ValidationException $e) {
                $this->assertNotEmpty($e->errors());
            }
        }
        $location->update(['is_active' => false]);
        try {
            app(GeofencedPunchService::class)->record($this->gps($location));
            $this->fail('Disabled fence accepted');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('location', $e->errors());
        }
        $this->assertSame(0, AttendanceLog::count());
        Queue::assertNothingPushed();
    }

    public function test_multiple_branch_and_standalone_work_locations_are_supported(): void
    {
        $this->enable();
        Queue::fake();
        $branch = Branch::create(['name' => 'North']);
        $one = $this->fence();
        $two = WorkLocation::create(['name' => 'North warehouse', 'branch_id' => $branch->id,
            'latitude' => 12, 'longitude' => 76, 'radius_meters' => 250, 'is_active' => true]);
        $service = app(GeofencedPunchService::class);
        $service->record($this->gps($one));
        $this->travel(2)->seconds();
        $log = $service->record($this->gps($two, 'out'));
        $this->travelBack();
        $this->assertSame($branch->id, $log->evidence['branch_id']);
        $this->assertSame(250, $log->evidence['radius_meters']);
        $this->assertStringContainsString('12%2C76', $two->google_maps_url);
        $this->assertEqualsWithDelta(111.2, $one->distanceTo(11.2598, 75.7804), 1);
        $this->assertTrue(Gate::forUser($this->manager)->allows('create', WorkLocation::class));
        $this->assertFalse(Gate::forUser($this->employee)->allows('create', WorkLocation::class));
        Livewire::test(LocationPunch::class)->assertSee('Work location')->assertSee('Check in');
    }

    public function test_geofenced_punch_still_requires_active_employee_and_password_change(): void
    {
        $location = $this->fence();
        $this->enable(change: false);
        try {
            app(GeofencedPunchService::class)->record($this->gps($location));
            $this->fail('First-login restriction bypassed');
        } catch (HttpException $e) {
            $this->assertSame(403, $e->getStatusCode());
        }
        $this->enable();
        $this->employee->update(['is_enabled' => false]);
        $this->expectException(HttpException::class);
        app(GeofencedPunchService::class)->record($this->gps($location));
    }

    public function test_portal_punches_follow_the_existing_night_shift_calculation(): void
    {
        $this->enable();
        Queue::fake();
        $location = $this->fence();
        $shift = Schedule::create(['name' => 'Night', 'type' => 'regular', 'valid_from' => '2026-01-01', 'status' => true,
            'rules' => ['start_time' => '22:00', 'end_time' => '06:00', 'end_day_offset' => 1, 'weekdays' => [0, 1, 2, 3, 4, 5, 6], 'full_day_minutes' => 480, 'half_day_minutes' => 240,
                'earliest_arrival_minutes' => 60, 'checkout_cutoff_minutes' => 120, 'punch_method' => 'device']]);
        app(ShiftRuleService::class)->sync($shift);
        app(ShiftAssignmentService::class)->setDefault($shift, '2026-01-01');
        try {
            Carbon::setTestNow(Carbon::parse('2026-11-02 22:00:00', TenantTimezone::get()));
            $in = app(GeofencedPunchService::class)->record($this->gps($location));
            Carbon::setTestNow(Carbon::parse('2026-11-03 06:00:00', TenantTimezone::get()));
            $out = app(GeofencedPunchService::class)->record($this->gps($location, 'out'));
            $this->assertSame(0, AttendanceLog::count());
            app(PunchApprovalService::class)->decide($out, $this->manager, true);
            app(PunchApprovalService::class)->decide($in, $this->manager, true);
            $day = app(AttendanceCalculationService::class)->calculate($this->employee, Carbon::parse('2026-11-02'));
            $this->assertSame(480, $day->approved_worked_minutes);
            $this->assertSame('present', $day->status);
            $this->assertSame('portal', $day->source);
            $this->assertSame('portal', $day->occurrences->first()->source);
            $this->assertSame('2026-11-03', $day->occurrences->first()->last_out_at->toDateString());
        } finally {
            Carbon::setTestNow();
        }
    }

    public function test_same_second_punch_collision_returns_a_validation_error_without_losing_evidence(): void
    {
        $this->enable();
        Queue::fake();
        $location = $this->fence();
        Carbon::setTestNow(Carbon::parse('2026-11-03 10:00:00', TenantTimezone::get()));
        try {
            $service = app(GeofencedPunchService::class);
            $one = $service->record($this->gps($location));
            $two = $service->record($this->gps($location, 'out'));
            app(PunchApprovalService::class)->decide($one, $this->manager, true);
            try {
                app(PunchApprovalService::class)->decide($two, $this->manager, true);
                $this->fail('Same-second collision was accepted');
            } catch (ValidationException $e) {
                $this->assertArrayHasKey('review_note', $e->errors());
            }
            $this->assertSame('pending', $two->fresh()->state);
            $this->assertSame(1, AttendanceLog::count());
            $this->assertSame(0, AttendanceLog::first()->status);
        } finally {
            Carbon::setTestNow();
        }
    }

    public function test_hr_can_manage_map_locations_and_employee_cannot_open_management(): void
    {
        $this->actingAs($this->manager, 'web');
        Filament::setCurrentPanel(Filament::getPanel('tenant'));
        $page = Livewire::test(ManageWorkLocations::class);
        $page->mountAction('create')->assertMountedActionModalSee('Use my location')->assertMountedActionModalSee('Open in Google Maps');
        $page->unmountAction();
        $page->callAction('create', ['name' => 'HQ', 'latitude' => 11.2588, 'longitude' => 75.7804, 'radius_meters' => 100, 'is_active' => true])->assertHasNoActionErrors();
        $this->assertSame(1, WorkLocation::count());
        $page->callAction('create', ['name' => 'Invalid', 'latitude' => 91, 'longitude' => 75, 'radius_meters' => 0, 'is_active' => true])->assertHasActionErrors(['latitude', 'radius_meters']);
        $this->assertSame(1, WorkLocation::count());
        $this->actingAs($this->employee, 'web');
        Livewire::test(ManageWorkLocations::class)->assertForbidden();
    }

    public function test_manual_punch_photo_is_required_private_and_approval_is_idempotent(): void
    {
        $this->enable();
        Queue::fake();
        $location = $this->fence();
        foreach (['', 'data:image/jpeg;base64,broken'] as $photo) {
            try {
                app(GeofencedPunchService::class)->record(array_replace($this->gps($location), ['photo' => $photo]));
                $this->fail('Invalid photo accepted');
            } catch (ValidationException $exception) {
                $this->assertArrayHasKey('photo', $exception->errors());
            }
        }
        $request = app(GeofencedPunchService::class)->record($this->gps($location));
        $this->assertSame(0, AttendanceLog::count());
        $this->assertStringStartsWith('BIOPHOTO-ENC1:', Storage::disk('local')->get($request->photo_path));
        $this->get($request->photo_url)->assertOk()->assertHeader('Content-Type', 'image/jpeg')->assertHeader('Cache-Control', 'max-age=0, no-store, private');
        $other = User::create(['name' => 'Other employee', 'pin' => 'other-photo', 'email' => 'photo@test.local', 'device_password' => '123456', 'is_enabled' => true]);
        app(PortalAccess::class)->reset($this->manager, $other);
        app(PortalAccess::class)->changePassword($other, ['password' => 'Strong-Portal123!', 'password_confirmation' => 'Strong-Portal123!']);
        $this->actingAs($other->fresh(), 'employee');
        $this->get($request->photo_url)->assertNotFound();
        $this->actingAs($this->manager, 'web');
        $this->manager->update(['is_enabled' => false]);
        $this->get($request->photo_url)->assertNotFound();
        $this->manager->update(['is_enabled' => true]);
        $this->get($request->photo_url)->assertOk();
        app(PunchApprovalService::class)->decide($request, $this->manager, true);
        app(PunchApprovalService::class)->decide($request, $this->manager, true);
        $this->assertSame(1, AttendanceLog::count());
        $this->assertSame('approved', $request->fresh()->state);
        Queue::assertPushed(RefreshAttendanceDay::class, 2);
        $this->expectException(HttpException::class);
        app(PunchApprovalService::class)->decide($request, $other, false, 'Not allowed');
    }

    public function test_hr_reviews_photo_in_separate_page_and_rejection_never_creates_attendance(): void
    {
        $this->enable();
        Queue::fake();
        $request = app(GeofencedPunchService::class)->record($this->gps($this->fence()));
        $this->actingAs($this->manager, 'web');
        Filament::setCurrentPanel(Filament::getPanel('tenant'));
        $page = Livewire::test(ManagePortalPunches::class)->assertCanSeeTableRecords([$request]);
        $page->mountTableAction('approve', $request)->assertMountedActionModalSee('Captured photo')
            ->assertMountedActionModalSee('GPS accuracy')->assertMountedActionModalSee('5.00')
            ->assertMountedActionModalDontSee('Reviewed by');
        $page->unmountAction();
        $page->callTableAction('reject', $request, data: ['review_note' => 'Photo does not show the employee'])->assertHasNoActionErrors();
        $this->assertSame('rejected', $request->fresh()->state);
        $this->assertSame(0, AttendanceLog::count());
        Queue::assertNothingPushed();
        $page->filterTable('state', 'rejected')->mountTableAction('view', $request->fresh())
            ->assertMountedActionModalSee('Reviewed by')->assertMountedActionModalSee('Photo does not show the employee');
        $this->actingAs($this->employee, 'web');
        Livewire::test(ManagePortalPunches::class)->assertForbidden();
    }

    public function test_native_manual_approval_records_original_time_and_missing_photo_blocks_approval(): void
    {
        $this->enable();
        Queue::fake();
        $request = app(GeofencedPunchService::class)->record($this->gps($this->fence()));
        $recorded = $request->punched_at->toDateTimeString();
        $this->actingAs($this->manager, 'web');
        Filament::setCurrentPanel(Filament::getPanel('tenant'));
        $this->travel(1)->hours();
        Livewire::test(ManagePortalPunches::class)
            ->callTableAction('approve', $request, data: ['review_note' => 'Photo checked'])->assertHasNoActionErrors();
        $this->travelBack();
        $this->assertSame($recorded, AttendanceLog::first()->punched_at->toDateTimeString());
        $this->assertSame('approved', $request->fresh()->state);
        $this->assertSame($this->manager->id, $request->fresh()->reviewed_by_user_id);
        $this->actingAs($this->employee, 'employee');
        $this->travel(2)->seconds();
        $missing = app(GeofencedPunchService::class)->record($this->gps($this->fence(), 'out'));
        $this->travelBack();
        Storage::disk('local')->delete($missing->photo_path);
        try {
            app(PunchApprovalService::class)->decide($missing, $this->manager, true);
            $this->fail('Missing photo was approved');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('review_note', $exception->errors());
        }
        $this->assertSame('pending', $missing->fresh()->state);
        $this->assertSame(1, AttendanceLog::count());
    }

    public function test_employee_password_minimum_is_eight_characters_in_the_form_and_service(): void
    {
        $this->enable(change: false);
        Livewire::test(ChangePassword::class)->fillForm(['password' => 'Abc123!', 'password_confirmation' => 'Abc123!'])
            ->call('save')->assertHasFormErrors(['password']);
        try {
            app(PortalAccess::class)->changePassword($this->employee, ['password' => 'Abc123!', 'password_confirmation' => 'Abc123!']);
            $this->fail('Seven-character password was accepted');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('password', $exception->errors());
        }
        Livewire::test(ChangePassword::class)->fillForm(['password' => 'Abcd123!', 'password_confirmation' => 'Abcd123!'])
            ->call('save')->assertHasNoFormErrors();
        $this->assertTrue(Hash::check('Abcd123!', $this->employee->fresh()->password));
        $this->assertFalse($this->employee->fresh()->requires_password_change);
    }

    public function test_geofence_disabled_keeps_gps_without_requiring_a_work_location(): void
    {
        $this->enable();
        Queue::fake();
        $location = $this->fence();
        EmployeePortalSetting::whereKey(1)->update(['geofencing_enabled' => false]);
        $data = $this->gps($location);
        unset($data['location_id']);
        $data['latitude'] = 40.7128;
        $data['longitude'] = -74.006;
        $data['accuracy'] = 15000;
        $request = app(GeofencedPunchService::class)->record($data);
        $this->assertFalse($request->evidence['geofencing_enabled']);
        $this->assertNull($request->work_location_id);
        $this->assertEquals(40.7128, $request->evidence['latitude']);
        $this->assertEquals(-74.006, $request->evidence['longitude']);
        $this->assertEquals(15000, $request->evidence['accuracy_meters']);
        $this->assertSame('pending', $request->state);
        $this->assertSame(0, AttendanceLog::count());
        $this->actingAs($this->manager, 'web');
        Filament::setCurrentPanel(Filament::getPanel('tenant'));
        Livewire::test(ManagePortalPunches::class)->mountTableAction('approve', $request)
            ->assertMountedActionModalSee('Not required')->assertMountedActionModalSee('Captured photo')
            ->assertMountedActionModalSee('40.7128');
        app(PunchApprovalService::class)->decide($request, $this->manager, true);
        $this->assertSame(1, AttendanceLog::count());
    }

    public function test_server_enforces_the_current_geofence_setting_even_for_stale_forms(): void
    {
        $this->enable();
        Queue::fake();
        $location = $this->fence();
        $data = $this->gps($location);
        unset($data['location_id']);
        try {
            app(GeofencedPunchService::class)->record($data);
            $this->fail('GPS was not required');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('location_id', $exception->errors());
        }
        $this->assertSame(0, PortalPunch::count());
    }

    public function test_location_is_required_even_when_geofencing_is_disabled(): void
    {
        $this->enable();
        $data = $this->gps($this->fence());
        EmployeePortalSetting::whereKey(1)->update(['geofencing_enabled' => false]);
        unset($data['latitude']);
        try {
            app(GeofencedPunchService::class)->record($data);
            $this->fail('Location was not required');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('latitude', $exception->errors());
        }
    }

    public function test_pending_check_in_shows_present_without_approving_attendance(): void
    {
        $this->enable();
        Queue::fake();
        $request = app(GeofencedPunchService::class)->record($this->gps($this->fence()));
        $widget = Livewire::test(PortalOverview::class)->instance();
        $stats = (new \ReflectionMethod($widget, 'getStats'))->invoke($widget);
        $this->assertSame('Present', $stats[0]->getValue());
        $this->assertSame('Pending approval', $stats[0]->getDescription());
        $this->assertSame('pending', $request->state);
        $this->assertSame(0, AttendanceLog::count());
    }

    public function test_hr_can_toggle_geofencing_in_organisation_settings(): void
    {
        $this->actingAs($this->manager, 'web');
        Filament::setCurrentPanel(Filament::getPanel('tenant'));
        $this->fence();
        Livewire::test(OrganisationSettings::class)
            ->fillForm(['geofencing_enabled' => false])->call('save')->assertHasNoFormErrors();
        $this->assertFalse(EmployeePortalSetting::geofencingEnabled());
        Livewire::test(OrganisationSettings::class)
            ->fillForm(['geofencing_enabled' => true])->call('save')->assertHasNoFormErrors();
        $this->assertTrue(EmployeePortalSetting::geofencingEnabled());
    }

    public function test_attendance_review_filters_are_scoped_to_the_employee(): void
    {
        $this->enable();
        $mine = AttendanceDay::create(['user_id' => $this->employee->id, 'work_date' => today(), 'status' => 'present']);
        $other = AttendanceDay::create(['user_id' => $this->manager->id, 'work_date' => today(), 'status' => 'present']);
        $mine->approvals()->create(['type' => 'overtime', 'state' => 'pending', 'calculation_revision' => 1, 'proposal' => []]);
        $other->approvals()->create(['type' => 'attendance', 'state' => 'pending', 'calculation_revision' => 1, 'proposal' => []]);
        Livewire::test(Attendance::class)->filterTable('review_state', 'pending')->assertCanSeeTableRecords([$mine])->assertCanNotSeeTableRecords([$other]);
        Livewire::test(Attendance::class)->filterTable('review_type', 'attendance')->assertCanNotSeeTableRecords([$mine, $other]);
        Livewire::test(Attendance::class)->filterTable('review_type', 'overtime')->assertCanSeeTableRecords([$mine])->assertCanNotSeeTableRecords([$other]);
    }

    public function test_geofence_default_preserves_existing_active_locations(): void
    {
        $migration = require database_path('migrations/tenant/2026_11_03_122434_create_employee_portal_settings.php');
        WorkLocation::create(['name' => 'Existing', 'latitude' => 11, 'longitude' => 75, 'radius_meters' => 100, 'is_active' => true]);
        $migration->down();
        $migration->up();
        $this->assertTrue(EmployeePortalSetting::geofencingEnabled());
        WorkLocation::query()->update(['is_active' => false]);
        $migration->down();
        $migration->up();
        $this->assertFalse(EmployeePortalSetting::geofencingEnabled());
    }

    public function test_today_summary_carries_a_check_in_across_midnight_only_for_its_night_shift(): void
    {
        $this->enable();
        Queue::fake();
        $location = $this->fence();
        $shift = Schedule::create(['name' => 'Night', 'type' => 'regular', 'valid_from' => '2026-01-01', 'status' => true,
            'rules' => ['start_time' => '22:00', 'end_time' => '06:00', 'end_day_offset' => 1, 'weekdays' => [0, 1, 2, 3, 4, 5, 6],
                'full_day_minutes' => 480, 'half_day_minutes' => 240, 'earliest_arrival_minutes' => 60, 'checkout_cutoff_minutes' => 120, 'punch_method' => 'device']]);
        app(ShiftRuleService::class)->sync($shift);
        app(ShiftAssignmentService::class)->setDefault($shift, '2026-01-01');
        try {
            Carbon::setTestNow(Carbon::parse('2026-11-02 10:00:00', TenantTimezone::get()));
            app(GeofencedPunchService::class)->record($this->gps($location));
            Carbon::setTestNow(Carbon::parse('2026-11-03 01:00:00', TenantTimezone::get()));
            $stats = (new \ReflectionMethod(PortalOverview::class, 'getStats'))->invoke(Livewire::test(PortalOverview::class)->instance());
            $this->assertSame('—', $stats[0]->getValue());
            Carbon::setTestNow(Carbon::parse('2026-11-02 22:00:00', TenantTimezone::get()));
            app(GeofencedPunchService::class)->record($this->gps($location));
            Carbon::setTestNow(Carbon::parse('2026-11-03 01:00:00', TenantTimezone::get()));
            $stats = (new \ReflectionMethod(PortalOverview::class, 'getStats'))->invoke(Livewire::test(PortalOverview::class)->instance());
            $this->assertSame('Present', $stats[0]->getValue());
            $this->assertSame('Pending approval', $stats[0]->getDescription());
        } finally {
            Carbon::setTestNow();
        }
    }
}
