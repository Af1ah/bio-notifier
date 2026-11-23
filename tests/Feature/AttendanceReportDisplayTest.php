<?php

namespace Tests\Feature;

use App\Filament\Tenant\Pages\Reports;
use App\Livewire\Tenant\UserAttendanceCalendar;
use App\Models\AttendanceDay;
use App\Models\User;
use App\Services\Attendance\AttendanceDayDisplay;
use App\Services\Attendance\ReportService;
use Illuminate\Support\Facades\Artisan;
use Livewire\Livewire;
use Tests\TestCase;

class AttendanceReportDisplayTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Artisan::call('migrate:fresh', ['--path' => database_path('migrations/tenant'), '--realpath' => true, '--force' => true]);
    }

    public function test_report_shows_numeric_hours_half_days_and_absent_unapproved_checkouts(): void
    {
        $user = User::create(['name' => 'Hours employee', 'pin' => 'hours']);
        AttendanceDay::create(['user_id' => $user->id, 'work_date' => '2026-09-01', 'status' => 'present', 'approved_worked_minutes' => 510]);
        AttendanceDay::create(['user_id' => $user->id, 'work_date' => '2026-09-02', 'status' => 'half_day', 'approved_worked_minutes' => 240]);
        $day = AttendanceDay::create(['user_id' => $user->id, 'work_date' => '2026-09-03', 'status' => 'present', 'candidate_worked_minutes' => 540, 'calculation_revision' => 2]);
        $request = $day->approvals()->create(['type' => 'attendance', 'state' => 'pending', 'calculation_revision' => 2, 'proposal' => ['reason' => 'missing_checkout']]);
        $service = app(ReportService::class);
        $report = $service->generateReport([$user->id], '2026-09-01', '2026-09-04');
        $row = $report['data'][0];
        $this->assertSame('08:30', $row['daily']['2026-09-01']['clock']);
        $this->assertArrayNotHasKey('decimal_hours', $row['daily']['2026-09-01']);
        $this->assertSame('warning', $row['daily']['2026-09-02']['color']);
        $this->assertSame('H', $row['daily']['2026-09-02']['status']);
        $this->assertSame('A', $row['daily']['2026-09-03']['status']);
        $this->assertSame('danger', $row['daily']['2026-09-03']['color']);
        $this->assertSame('00:00', $row['daily']['2026-09-03']['clock']);
        $this->assertSame('gray', $row['daily']['2026-09-04']['color']);
        $this->assertSame(750, $row['total_minutes']);
        $this->assertSame('12:30', $row['total_display']);
        $this->assertArrayNotHasKey('total_decimal_hours', $row);
        $this->assertSame([1, 1, 1], [$row['present'], $row['half_day'], $row['absent']]);
        $this->assertSame('present', $day->fresh()->status);
        $this->assertSame(540, $day->fresh()->candidate_worked_minutes);
        $request->update(['state' => 'approved']);
        $day->update(['approved_worked_minutes' => 540]);
        $approved = $service->generateReport([$user->id], '2026-09-03', '2026-09-03')['data'][0];
        $this->assertSame('P', $approved['daily']['2026-09-03']['status']);
        $this->assertSame('09:00', $approved['daily']['2026-09-03']['clock']);
    }

    public function test_rejected_checkout_stays_absent_but_old_revisions_do_not_override_current_attendance(): void
    {
        $user = User::create(['name' => 'Review employee', 'pin' => 'review']);
        $day = AttendanceDay::create(['user_id' => $user->id, 'work_date' => '2026-09-01', 'status' => 'half_day', 'approved_worked_minutes' => 240, 'calculation_revision' => 2]);
        $request = $day->approvals()->create(['type' => 'attendance', 'state' => 'rejected', 'calculation_revision' => 2, 'proposal' => ['reason' => 'missing_checkout']]);
        $this->assertSame('absent', AttendanceDayDisplay::forDay($day)['status']);
        $request->update(['calculation_revision' => 1]);
        $this->assertSame('half_day', AttendanceDayDisplay::forDay($day->fresh())['status']);
        $request->update(['calculation_revision' => 2, 'state' => 'pending']);
        $day->approvals()->create(['type' => 'attendance', 'state' => 'approved', 'calculation_revision' => 2,
            'proposal' => ['reason' => 'manual_correction', 'proposed_out_at' => '2026-09-01 13:00:00']]);
        $this->assertSame('half_day', AttendanceDayDisplay::forDay($day->fresh())['status']);
    }

    public function test_report_cell_contains_one_worked_time_and_an_accessible_status(): void
    {
        $hours = ['clock' => '08:30', 'label' => 'Half day', 'color' => 'warning'];
        $html = view('filament.tenant.attendance.report-hours', compact('hours'))->render();
        $this->assertSame('08:30h', preg_replace('/\s+/', ' ', trim(strip_tags($html))));
        $this->assertStringContainsString('attendance-report-hours--warning', $html);
        $this->assertStringContainsString('aria-label="Half day:', $html);
    }

    public function test_calendar_uses_the_same_unapproved_checkout_display(): void
    {
        $user = User::create(['name' => 'Calendar employee', 'pin' => 'calendar', 'privilege' => 14]);
        $day = AttendanceDay::create(['user_id' => $user->id, 'work_date' => '2026-09-01', 'status' => 'present', 'candidate_worked_minutes' => 510, 'calculation_revision' => 1]);
        $day->approvals()->create(['type' => 'attendance', 'state' => 'pending', 'calculation_revision' => 1, 'proposal' => ['reason' => 'missing_checkout']]);
        $this->actingAs($user);
        Livewire::test(UserAttendanceCalendar::class, ['record' => $user])->set('year', 2026)->set('month', 9)
            ->assertSee('Absent')->assertSee('00:00')->assertDontSee('08:30');
    }

    public function test_csv_and_pdf_templates_only_include_worked_time(): void
    {
        $user = User::create(['name' => 'Export employee', 'pin' => 'export']);
        AttendanceDay::create(['user_id' => $user->id, 'work_date' => '2026-09-01', 'status' => 'half_day', 'approved_worked_minutes' => 270]);
        $reportData = app(ReportService::class)->generateReport([$user->id], '2026-09-01', '2026-09-01');
        $page = new class extends Reports
        {
            public array $fixture;

            public function generateReportData()
            {
                return $this->fixture;
            }
        };
        $page->fixture = $reportData;
        ob_start();
        ($page->downloadCsv()->getCallback())();
        $csv = ob_get_clean();
        $lines = array_map('str_getcsv', explode("\n", trim($csv)));
        $this->assertSame('Sep 01 HH:MM', $lines[0][1]);
        $this->assertStringNotContainsString('Decimal', $csv);
        $this->assertSame('04:30', $lines[1][1]);
        $this->assertCount(count($lines[0]), $lines[1]);
        $html = view('filament.tenant.pages.pdf.report-matrix', ['reportData' => $reportData, 'activeTab' => 'monthly',
            'fromDate' => '2026-09-01', 'toDate' => '2026-09-01', 'tenantName' => 'Export test'])->render();
        $this->assertStringContainsString('04:30h', $html);
        $this->assertStringNotContainsString('<small>', $html);
        $this->assertStringContainsString('attendance-report-hours--warning', $html);
    }
}
