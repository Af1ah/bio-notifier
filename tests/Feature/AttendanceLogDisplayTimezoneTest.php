<?php

namespace Tests\Feature;

use App\Filament\Tenant\Pages\Reports;
use App\Filament\Tenant\Resources\AttendanceApprovals\Pages\ListAttendanceApprovals;
use App\Filament\Tenant\Resources\AttendanceDays\Pages\ListAttendanceDays;
use App\Filament\Tenant\Resources\AttendanceLogResource\Pages\ListAttendanceLogs;
use App\Filament\Tenant\Resources\AttendanceLogResource\Pages\ViewAttendanceLog;
use App\Filament\Tenant\Resources\DeviceResource\Pages\ViewDevice;
use App\Filament\Tenant\Resources\DeviceResource\RelationManagers\AttendanceLogsRelationManager;
use App\Filament\Tenant\Widgets\RecentAttendanceLogs;
use App\Models\AttendanceCorrection;
use App\Models\AttendanceDay;
use App\Models\AttendanceLog;
use App\Models\Device;
use App\Models\ManualAttendanceEntry;
use App\Models\Organisation;
use App\Models\Schedule;
use App\Models\User;
use App\Services\Attendance\ApprovalService;
use App\Services\Attendance\AttendancePunchEvidence;
use App\Services\Attendance\ShiftRuleService;
use App\Services\Support\TenantTimezone;
use Filament\Facades\Filament;
use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\URL;
use Livewire\Livewire;
use Tests\TestCase;

class AttendanceLogDisplayTimezoneTest extends TestCase
{
    private array $companies = [];

    protected function setUp(): void
    {
        parent::setUp();
        Artisan::call('migrate:fresh', ['--force' => true]);
    }

    protected function tearDown(): void
    {
        tenancy()->end();
        foreach ($this->companies as $company) {
            $company->delete();
            File::deleteDirectory(base_path('storage/tenant'.$company->id));
        }
        parent::tearDown();
    }

    public function test_log_table_and_read_only_detail_keep_each_companys_local_punch_time(): void
    {
        config(['app.timezone' => 'UTC']);
        foreach (['Asia/Kolkata', 'Asia/Dubai'] as $index => $timezone) {
            $company = Organisation::create(['name' => 'Display test', 'shortname' => 'display-'.$index, 'device_integration' => 'ebio', 'timezone' => $timezone]);
            $this->companies[] = $company;
            tenancy()->initialize($company);
            URL::defaults(['tenant' => $company->shortname]);
            $admin = User::create(['name' => 'Display admin', 'pin' => '1', 'privilege' => 14, 'email' => 'display@test.local', 'password' => bcrypt('test-only')]);
            $this->actingAs($admin, 'web');
            Filament::setCurrentPanel(Filament::getPanel('tenant'));
            Filament::bootCurrentPanel();
            $device = Device::create(['serial_number' => 'TIME-'.$index, 'name' => 'Time test']);
            $log = AttendanceLog::withoutEvents(fn () => AttendanceLog::create(['device_id' => $device->id, 'pin' => '1', 'punched_at' => '2026-10-20 18:37:09', 'status' => 0, 'verify_type' => 1]));
            $raw = $log->getRawOriginal('punched_at');
            $widget = Livewire::test(RecentAttendanceLogs::class);
            $this->assertStringContainsString('18:37', $widget->instance()->getTable()->getColumn('punched_at')->formatState($log->punched_at));
            $relation = Livewire::test(AttendanceLogsRelationManager::class,
                ['ownerRecord' => $device, 'pageClass' => ViewDevice::class]);
            $this->assertStringContainsString('18:37:09', $relation->instance()->getTable()->getColumn('punched_at')->formatState($log->punched_at));
            $table = Livewire::test(ListAttendanceLogs::class)->assertCanSeeTableRecords([$log]);
            $column = $table->instance()->getTable()->getColumn('punched_at');
            $this->assertSame($timezone, $column->getTimezone());
            $this->assertStringContainsString('18:37:09', $column->formatState($log->punched_at));
            $view = Livewire::test(ViewAttendanceLog::class, ['record' => $log->getRouteKey()]);
            $field = $view->instance()->form->getComponent('punched_at', withHidden: true);
            $this->assertSame($timezone, $field->getTimezone());
            $this->assertStringContainsString('18:37:09', $field->getRawState());
            $this->assertSame($raw, $log->fresh()->getRawOriginal('punched_at'));
            tenancy()->end();
        }
    }

    private function loginCompany(string $name, string $timezone): User
    {
        $company = Organisation::create(['name' => $name, 'shortname' => $name, 'device_integration' => 'ebio', 'timezone' => $timezone]);
        $this->companies[] = $company;
        tenancy()->initialize($company);
        URL::defaults(['tenant' => $company->shortname]);
        $admin = User::create(['name' => 'Display admin', 'pin' => '1', 'privilege' => 14, 'email' => 'display@test.local', 'password' => bcrypt('test-only')]);
        $this->actingAs($admin, 'web');
        Filament::setCurrentPanel(Filament::getPanel('tenant'));
        Filament::bootCurrentPanel();

        return $admin;
    }

    public function test_shared_datetime_defaults_follow_tenant_context_without_shifting_shift_clocks(): void
    {
        $column = TextColumn::make('created_at')->dateTime('Y-m-d H:i');
        $entry = TextEntry::make('paid_at')->dateTime('Y-m-d H:i')->container(Schema::make());
        $shift = TextColumn::make('start_time')->time('H:i');
        $instant = Carbon::parse('2026-10-20 20:00:00', 'UTC');
        foreach (['Asia/Kolkata' => '2026-10-21 01:30', 'Asia/Dubai' => '2026-10-21 00:00'] as $timezone => $expected) {
            $this->loginCompany('defaults-'.count($this->companies), $timezone);
            $this->assertSame($expected, $column->formatState($instant));
            $this->assertSame($expected, $entry->formatState($instant));
            $this->assertSame($expected, TenantTimezone::formatInstant($instant, 'Y-m-d H:i'));
            $this->assertSame('09:00', $shift->formatState('09:00:00'));
            $this->assertSame('20:00', $instant->format('H:i'));
            $this->assertSame('UTC', config('app.timezone'));
            Carbon::setTestNow($instant);
            try {
                $reports = new Reports;
                $reports->mount();
                $this->assertSame('2026-10-21', $reports->tableFilters['date_range']['from_date']);
            } finally {
                Carbon::setTestNow();
            }
            tenancy()->end();
        }
        $this->assertSame('UTC', $column->getTimezone());
    }

    public function test_overnight_correction_form_keeps_local_dates_through_submission(): void
    {
        foreach (['Asia/Kolkata', 'Asia/Dubai'] as $timezone) {
            $actor = $this->loginCompany('correction-'.count($this->companies), $timezone);
            $day = AttendanceDay::create(['user_id' => $actor->id, 'work_date' => '2026-10-20', 'status' => 'present']);
            $occ = $day->occurrences()->create(['window_starts_at' => '2026-10-20 16:00:00', 'window_ends_at' => '2026-10-21 02:00:00',
                'first_in_at' => '2026-10-20 17:00:00', 'last_out_at' => '2026-10-21 01:00:00']);
            $before = $occ->getRawOriginal('first_in_at');
            Livewire::test(ListAttendanceDays::class)
                ->mountTableAction('correct', $day)
                ->assertTableActionDataSet(['proposed_in_at' => '2026-10-20 17:00', 'proposed_out_at' => '2026-10-21 01:00'])
                ->setTableActionData(['proposed_in_at' => 'not-a-date', 'reason' => 'Verified overnight times'])
                ->callMountedTableAction()->assertHasTableActionErrors(['proposed_in_at' => 'date'])
                ->setTableActionData(['proposed_in_at' => '2026-10-20 17:00', 'reason' => 'Verified overnight times'])
                ->callMountedTableAction()->assertHasNoTableActionErrors();
            $correction = AttendanceCorrection::where('attendance_day_id', $day->id)->firstOrFail();
            $this->assertSame('2026-10-20 17:00:00', $correction->getRawOriginal('proposed_in_at'));
            $this->assertSame('2026-10-21 01:00:00', $correction->getRawOriginal('proposed_out_at'));
            $this->assertSame('pending', $correction->state);
            $this->assertSame($before, $occ->fresh()->getRawOriginal('first_in_at'));
            tenancy()->end();
        }
    }

    public function test_missing_checkout_approval_keeps_local_time_on_submission(): void
    {
        $this->loginCompany('checkout-timezone', 'Asia/Kolkata');
        $day = AttendanceDay::create(['user_id' => auth()->id(), 'work_date' => '2026-10-20', 'status' => 'pending_approval']);
        $occ = $day->occurrences()->create(['window_starts_at' => '2026-10-20 16:00:00', 'window_ends_at' => '2026-10-21 02:00:00',
            'first_in_at' => '2026-10-20 17:00:00', 'assumed_out_at' => '2026-10-21 01:00:00']);
        $approval = $day->approvals()->create(['attendance_occurrence_id' => $occ->id, 'type' => 'attendance', 'state' => 'pending',
            'calculation_revision' => 1, 'proposal' => ['reason' => 'missing_checkout']]);
        $service = \Mockery::mock(ApprovalService::class);
        $service->shouldReceive('decide')->once()->withArgs(fn ($record, $actor, $approve, $reason = null, $checkoutAt = null) => $record->id === $approval->id && $approve && $checkoutAt === '2026-10-21 01:00');
        $this->app->instance(ApprovalService::class, $service);
        Livewire::test(ListAttendanceApprovals::class)
            ->mountTableAction('approve', $approval)
            ->assertTableActionDataSet(['checkout_at' => '2026-10-21 01:00'])
            ->callMountedTableAction()->assertHasNoTableActionErrors();
        $this->assertSame('2026-10-21 01:00:00', $occ->fresh()->getRawOriginal('assumed_out_at'));
    }

    public function test_employee_date_entry_reuses_correction_and_loads_overnight_raw_punches(): void
    {
        $actor = $this->loginCompany('entry-context', 'Asia/Kolkata');
        $day = AttendanceDay::create(['user_id' => $actor->id, 'work_date' => '2026-10-20', 'status' => 'present']);
        $occ = $day->occurrences()->create(['window_starts_at' => '2026-10-20 16:00:00', 'window_ends_at' => '2026-10-21 02:00:00',
            'first_in_at' => '2026-10-20 17:00:00', 'last_out_at' => '2026-10-21 01:00:00']);
        $device = Device::create(['serial_number' => 'RAW-TIME', 'name' => 'Original device']);
        AttendanceLog::withoutEvents(fn () => AttendanceLog::create(['device_id' => $device->id, 'pin' => $actor->pin, 'punched_at' => '2026-10-21 01:00:00', 'status' => 1]));
        AttendanceLog::withoutEvents(fn () => AttendanceLog::create(['device_id' => $device->id, 'pin' => 'another-employee', 'punched_at' => '2026-10-20 19:55:00', 'status' => 0]));
        $page = Livewire::test(ListAttendanceDays::class)->mountAction('manualAttendance')
            ->setActionData(['user_id' => $actor->id, 'work_date' => '2026-10-20'])
            ->assertActionDataSet(['entry_mode' => 'correction', 'attendance_occurrence_id' => $occ->id, 'proposed_in_at' => '2026-10-20 17:00']);
        $this->assertCount(1, app(AttendancePunchEvidence::class)->read($actor->id, '2026-10-20', $day)['logs']);
        $rawPanel = (string) $page->instance()->getSchema('mountedActionSchema0')->getComponent('raw_punches', withHidden: true)->getContent();
        $this->assertStringContainsString('21 Oct 2026 01:00:00', $rawPanel);
        $this->assertStringNotContainsString('19:55:00', $rawPanel);
        $page->setActionData(['proposed_out_at' => '2026-10-21 00:45', 'reason' => 'Reviewed actual departure'])
            ->callMountedAction()->assertHasNoActionErrors();
        $correction = AttendanceCorrection::firstOrFail();
        $this->assertSame('2026-10-21 00:45:00', $correction->getRawOriginal('proposed_out_at'));
        $this->assertSame('present', $day->fresh()->status);
        $this->assertSame(0, ManualAttendanceEntry::count());
        $this->assertSame(2, AttendanceLog::count());
        $page->assertSee('Waiting for approval');
        $day->approvals()->update(['state' => 'rejected']);
        Livewire::test(ListAttendanceDays::class)->assertDontSee('Waiting for approval');
    }

    public function test_selected_date_without_row_still_adds_manual_attendance(): void
    {
        $actor = $this->loginCompany('entry-manual', 'Asia/Dubai');
        $shift = Schedule::create(['name' => 'Day shift', 'type' => 'regular', 'valid_from' => '2026-10-01', 'status' => true,
            'rules' => ['start_time' => '09:00', 'end_time' => '17:00', 'weekdays' => [0, 1, 2, 3, 4, 5, 6], 'full_day_minutes' => 480, 'half_day_minutes' => 240,
                'earliest_arrival_minutes' => 60, 'checkout_cutoff_minutes' => 120, 'punch_method' => 'device']]);
        app(ShiftRuleService::class)->sync($shift);
        Livewire::test(ListAttendanceDays::class)->mountAction('manualAttendance')
            ->setActionData(['user_id' => $actor->id, 'work_date' => '2026-10-20'])
            ->assertActionDataSet(['entry_mode' => 'manual'])
            ->setActionData(['schedule_id' => $shift->id, 'in_time' => '09:00', 'out_time' => '17:00', 'reason' => 'Confirmed manual entry'])
            ->callMountedAction()->assertHasNoActionErrors()->assertSee('Waiting for approval');
        $this->assertSame(1, ManualAttendanceEntry::count());
        $this->assertSame(0, AttendanceCorrection::count());
    }
}
