<?php

namespace Tests\Feature;

use App\Filament\Tenant\Resources\AttendanceDays\Pages\ListAttendanceDays;
use App\Filament\Tenant\Resources\SalarySlips\Pages\ListSalarySlips;
use App\Models\AttendanceApproval;
use App\Models\AttendanceCalculationRun;
use App\Models\AttendanceDay;
use App\Models\AttendanceLog;
use App\Models\Branch;
use App\Models\Department;
use App\Models\Device;
use App\Models\EmployeeLeave;
use App\Models\Holiday;
use App\Models\LeaveType;
use App\Models\ManualAttendanceEntry;
use App\Models\SalarySlip;
use App\Models\Schedule;
use App\Models\TaskGroup;
use App\Models\User;
use App\Services\Attendance\ApprovalService;
use App\Services\Attendance\AttendanceBaselineService;
use App\Services\Attendance\AttendanceCalculationService;
use App\Services\Attendance\AttendanceEntryService;
use App\Services\Attendance\ShiftAssignmentService;
use App\Services\Attendance\ShiftRuleService;
use App\Services\Payroll\ManualPayrollReviewService;
use App\Services\Payroll\SalarySlipExportService;
use App\Services\Payroll\SalarySlipService;
use Barryvdh\DomPDF\PDF;
use Carbon\Carbon;
use Carbon\CarbonPeriod;
use Filament\Facades\Filament;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use Tests\TestCase;

class AttendanceEditingAndPayrollPeriodsTest extends TestCase
{
    private User $actor;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-08-03 23:00:00');
        Artisan::call('migrate:fresh', ['--path' => database_path('migrations/tenant'), '--realpath' => true, '--force' => true]);
        $this->actor = User::create(['name' => 'HR', 'pin' => 'hr', 'privilege' => 14, 'is_system_account' => true]);
        Filament::setCurrentPanel(Filament::getPanel('tenant'));
        URL::defaults(['tenant' => 'test']);
        $this->actingAs($this->actor);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function shift(string $name = 'Day', string $start = '09:00', string $end = '17:00', array $extra = []): Schedule
    {
        $shift = Schedule::create(['name' => $name, 'type' => 'regular', 'valid_from' => '2026-08-01', 'status' => true, 'rules' => array_merge(['start_time' => $start, 'end_time' => $end, 'weekdays' => [0, 1, 2, 3, 4, 5, 6], 'full_day_minutes' => 480, 'half_day_minutes' => 240, 'earliest_arrival_minutes' => 60, 'checkout_cutoff_minutes' => 120, 'punch_method' => 'device', 'overtime_basis' => 'after_required_hours', 'overtime_minimum_minutes' => 30], $extra)]);
        app(ShiftRuleService::class)->sync($shift);

        return $shift;
    }

    private function employee(string $pin = '1'): User
    {
        return User::create(['name' => 'Employee '.$pin, 'pin' => $pin]);
    }

    private function punches(User $user, array $times): void
    {
        $device = Device::firstOrCreate(['serial_number' => 'TEST']);
        foreach ($times as [$time,$status]) {
            AttendanceLog::create(['device_id' => $device->id, 'pin' => $user->pin, 'punched_at' => '2026-08-03 '.$time, 'status' => $status]);
        }
    }

    public function test_manual_entry_is_shift_linked_calculated_and_requires_approval(): void
    {
        $user = $this->employee();
        $shift = $this->shift();
        $day = app(AttendanceEntryService::class)->add($this->actor, ['user_id' => $user->id, 'schedule_id' => $shift->id, 'work_date' => '2026-08-03', 'in_at' => '2026-08-03 09:00', 'out_at' => '2026-08-03 17:00', 'reason' => 'Device unavailable']);
        $this->assertSame('manual', $day->source);
        $this->assertSame(480, $day->candidate_worked_minutes);
        $this->assertSame(0, $day->approved_worked_minutes);
        $this->assertSame($shift->id, $day->occurrences->first()->schedule_id);
        $this->assertSame(0, AttendanceLog::count());
        $this->assertSame(1, ManualAttendanceEntry::count());
        app(ApprovalService::class)->decide($day->approvals()->where('type', 'attendance')->firstOrFail(), $this->actor, true);
        $this->assertSame(480, $day->fresh()->approved_worked_minutes);
        $this->assertSame($day->id, app(AttendanceCalculationService::class)->calculate($user, Carbon::parse('2026-08-03'))->id);
    }

    public function test_actual_manual_entry_action_uses_the_selected_shift(): void
    {
        $user = $this->employee();
        $shift = $this->shift();
        Livewire::test(ListAttendanceDays::class)->callAction('manualAttendance', data: ['user_id' => $user->id, 'schedule_id' => $shift->id, 'work_date' => '2026-08-03', 'in_time' => '09:00', 'out_time' => '17:00', 'reason' => 'HR entered verified times'])->assertHasNoErrors();
        $this->assertSame(480, AttendanceDay::firstOrFail()->candidate_worked_minutes);
    }

    public function test_trailing_open_session_keeps_earlier_work_and_gaps_after_hr_checkout(): void
    {
        $user = $this->employee();
        $shift = $this->shift(extra: ['auto_checkout_enabled' => true, 'auto_checkout_time' => '17:00', 'auto_checkout_day_offset' => 0]);
        app(ShiftAssignmentService::class)->setDefault($shift, '2026-08-01');
        $this->punches($user, [['09:00', 0], ['12:00', 1], ['13:00', 0]]);
        $day = app(AttendanceCalculationService::class)->calculate($user, Carbon::parse('2026-08-03'));
        $this->assertSame(420, $day->candidate_worked_minutes);
        $approval = $day->approvals()->where('type', 'attendance')->firstOrFail();
        app(ApprovalService::class)->decide($approval, $this->actor, true, checkoutAt: '2026-08-03 16:00');
        $this->assertSame(360, $day->fresh()->approved_worked_minutes);
        $this->assertSame(3, AttendanceLog::count());
    }

    public function test_correcting_selected_shift_preserves_the_other_shift_and_original_status(): void
    {
        $user = $this->employee();
        $morning = $this->shift('Morning', '09:00', '12:00');
        $afternoon = $this->shift('Afternoon', '13:00', '17:00');
        app(ShiftAssignmentService::class)->assign('user', $user, ['schedule_ids' => [$morning->id, $afternoon->id], 'effective_from' => '2026-08-01']);
        $this->punches($user, [['09:00', 0], ['12:00', 1], ['13:00', 0], ['17:00', 1]]);
        $day = app(AttendanceCalculationService::class)->calculate($user, Carbon::parse('2026-08-03'));
        $first = $day->occurrences->first();
        $second = $day->occurrences->last();
        $before = $day->status;
        $correction = app(AttendanceEntryService::class)->correct($day, $this->actor, ['attendance_occurrence_id' => $second->id, 'proposed_in_at' => '2026-08-03 13:00', 'proposed_out_at' => '2026-08-03 16:00', 'reason' => 'Verified departure']);
        $this->assertSame($before, $day->fresh()->status);
        app(ApprovalService::class)->decide($day->approvals()->where('proposal->correction_id', $correction->id)->firstOrFail(), $this->actor, true);
        $this->assertSame(180, $first->fresh()->candidate_worked_minutes);
        $this->assertSame(180, $second->fresh()->candidate_worked_minutes);
        $this->assertSame(360, $day->fresh()->approved_worked_minutes);
        $this->assertSame(4, AttendanceLog::count());
        $this->assertDatabaseCount('attendance_day_revisions', 1);
    }

    public function test_foreign_shift_occurrence_cannot_be_used_in_a_correction(): void
    {
        $shift = $this->shift();
        app(ShiftAssignmentService::class)->setDefault($shift, '2026-08-01');
        $a = app(AttendanceCalculationService::class)->calculate($this->employee('a'), Carbon::parse('2026-08-03'));
        $b = app(AttendanceCalculationService::class)->calculate($this->employee('b'), Carbon::parse('2026-08-03'));
        $this->expectException(ModelNotFoundException::class);
        app(AttendanceEntryService::class)->correct($a, $this->actor, ['attendance_occurrence_id' => $b->occurrences->first()->id, 'proposed_in_at' => '2026-08-03 09:00', 'proposed_out_at' => '2026-08-03 17:00', 'reason' => 'Wrong row']);
    }

    public function test_legacy_baseline_adoption_records_original_decision_and_rejects_changed_evidence(): void
    {
        $user = $this->employee();
        $shift = $this->shift();
        app(ShiftAssignmentService::class)->setDefault($shift, '2026-08-01');
        $day = AttendanceDay::create(['user_id' => $user->id, 'work_date' => '2026-08-03', 'status' => 'present', 'approved_worked_minutes' => 480]);
        AttendanceApproval::create(['attendance_day_id' => $day->id, 'type' => 'attendance', 'state' => 'approved', 'calculation_revision' => 1, 'proposal' => []]);
        $service = app(AttendanceBaselineService::class);
        $signature = $service->evidence($day)['signature'];
        $this->punches($user, [['09:00', 0], ['17:00', 1]]);
        try {
            $service->adopt($day, $this->actor, 'Reviewed paper attendance', $signature, true);
            $this->fail('Changed evidence must invalidate the review.');
        } catch (ValidationException $e) {
            $this->assertDatabaseCount('attendance_baseline_adoptions', 0);
        }
        $service->adopt($day, $this->actor, 'Reviewed original approval against punches', $service->evidence($day)['signature'], true);
        $this->assertSame(480, $day->fresh()->approved_worked_minutes);
        $this->assertDatabaseCount('attendance_baseline_adoptions', 1);
        $this->assertSame('explicit_hr_review', $day->fresh()->explanation['baseline_source']);
        $this->assertSame(1, app(AttendanceCalculationService::class)->calculate($user, Carbon::parse('2026-08-03'))->calculation_revision);
    }

    private function manualCalendar(User $user, string $start, string $end): void
    {
        foreach (CarbonPeriod::create($start, $end) as $date) {
            AttendanceDay::create(['user_id' => $user->id, 'work_date' => $date->toDateString(), 'status' => 'present', 'source' => 'manual', 'approved_worked_minutes' => 480]);
        }
    }

    public function test_partial_month_prorates_actual_calendar_days_and_requires_current_manual_review(): void
    {
        $user = $this->employee();
        $user->payrollProfile()->create(['payroll_enabled' => true, 'basic_salary_minor' => 310000, 'housing_allowance_minor' => 31000, 'fixed_deduction_minor' => 3100, 'effective_from' => '2026-08-01', 'employment_starts_on' => '2026-08-16', 'employment_ends_on' => '2026-08-31']);
        $this->manualCalendar($user, '2026-08-16', '2026-08-31');
        $period = Carbon::parse('2026-08-01');
        $service = app(SalarySlipService::class);
        $review = app(ManualPayrollReviewService::class);
        try {
            $service->generate($user, $period);
            $this->fail('Unreviewed manual payroll must fail.');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('review', $e->errors());
        }
        $review->approve($user, $this->actor, $period, 'Confirmed join date and complete manual period', $review->signature($user, $period));
        $slip = $service->generate($user, $period);
        $this->assertSame(160000, $slip->basic_salary_minor);
        $this->assertSame(16000, $slip->housing_allowance_minor);
        $this->assertSame(1600, $slip->fixed_deduction_minor);
        $this->assertSame(16, $slip->calculation_snapshot['eligible_days']);
        AttendanceDay::where('user_id', $user->id)->first()->update(['status' => 'absent']);
        $this->assertFalse($review->approved($user, $period));
        $this->expectException(ValidationException::class);
        $service->transition($slip, $this->actor, 'reviewed');
    }

    private function slip(User $user, string $month, string $status = 'draft'): SalarySlip
    {
        return SalarySlip::create(['user_id' => $user->id, 'period_start' => $month.'-01', 'period_end' => Carbon::parse($month.'-01')->endOfMonth(), 'status' => $status, 'calculation_snapshot' => [], 'calculated_at' => now(), 'net_pay_minor' => 10000]);
    }

    public function test_payslip_page_defaults_hide_paid_and_older_months(): void
    {
        $user = $this->employee();
        $current = $this->slip($user, '2026-08');
        $paid = $this->slip($this->employee('paid'), '2026-08', 'paid');
        $old = $this->slip($this->employee('old'), '2026-07');
        Livewire::test(ListSalarySlips::class)->assertCanSeeTableRecords([$current])->assertCanNotSeeTableRecords([$paid, $old]);
    }

    public function test_csv_export_requires_one_month_and_never_contains_another_month(): void
    {
        $user = $this->employee();
        $this->slip($user, '2026-08');
        $this->slip($user, '2026-07');
        $service = app(SalarySlipExportService::class);
        try {
            $service->csv(SalarySlip::query());
            $this->fail('Month selection is mandatory.');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('period', $e->errors());
        }
        $response = $service->csv(SalarySlip::query(), Carbon::parse('2026-08-01'));
        ob_start();
        $response->sendContent();
        $csv = ob_get_clean();
        $this->assertStringContainsString('2026-08', $csv);
        $this->assertStringNotContainsString('2026-07', $csv);
    }

    public function test_export_global_filters_apply_before_specific_employee_selection(): void
    {
        $a = Branch::create(['name' => 'A']);
        $b = Branch::create(['name' => 'B']);
        $dept = Department::create(['name' => 'A Dept', 'branch_id' => $a->id]);
        $group = TaskGroup::create(['name' => 'Team']);
        $one = $this->employee('one');
        $one->update(['branch_id' => $a->id, 'department_id' => $dept->id]);
        $one->taskGroups()->attach($group);
        $two = $this->employee('two');
        $two->update(['branch_id' => $b->id]);
        $first = $this->slip($one, '2026-08');
        $second = $this->slip($two, '2026-08');
        $page = Livewire::test(ListSalarySlips::class)->instance();
        $page->tableFilters['branch_ids'] = ['values' => [$a->id]];
        $ids = $page->exportQuery(['period' => '2026-08', 'scope' => 'all_matching', 'department_ids' => [$dept->id], 'task_group_ids' => [$group->id], 'user_ids' => [$one->id, $two->id]])->pluck('id')->all();
        $this->assertSame([$first->id], $ids);
    }

    public function test_correction_of_one_shift_keeps_another_shift_approved_overtime(): void
    {
        $user = $this->employee();
        $morning = $this->shift('Morning OT', '09:00', '12:00', ['overtime_basis' => 'after_shift_end', 'earliest_arrival_minutes' => 0]);
        $afternoon = $this->shift('Afternoon OT', '14:00', '17:00', ['overtime_basis' => 'after_shift_end', 'earliest_arrival_minutes' => 0]);
        app(ShiftAssignmentService::class)->assign('user', $user, ['schedule_ids' => [$morning->id, $afternoon->id], 'effective_from' => '2026-08-01']);
        $this->punches($user, [['09:00', 0], ['13:00', 1], ['14:00', 0], ['17:00', 1]]);
        $day = app(AttendanceCalculationService::class)->calculate($user, Carbon::parse('2026-08-03'));
        $first = $day->occurrences->first();
        $second = $day->occurrences->last();
        $ot = $day->approvals()->where('type', 'overtime')->where('attendance_occurrence_id', $first->id)->firstOrFail();
        app(ApprovalService::class)->decide($ot, $this->actor, true);
        $correction = app(AttendanceEntryService::class)->correct($day, $this->actor, ['attendance_occurrence_id' => $second->id, 'proposed_in_at' => '2026-08-03 14:00', 'proposed_out_at' => '2026-08-03 16:00', 'reason' => 'Correct afternoon departure']);
        app(ApprovalService::class)->decide($day->approvals()->where('proposal->correction_id', $correction->id)->firstOrFail(), $this->actor, true);
        $this->assertSame('approved', $ot->fresh()->state);
        $this->assertSame(60, $first->fresh()->approved_overtime_minutes);
        $this->assertSame(60, $day->fresh()->approved_overtime_minutes);
    }

    public function test_filtered_export_can_select_an_older_month_without_leaking_current_month(): void
    {
        $user = $this->employee();
        $this->slip($user, '2026-08');
        $old = $this->slip($user, '2026-07');
        $page = Livewire::test(ListSalarySlips::class)->instance();
        $query = $page->exportQuery(['period' => '2026-07', 'scope' => 'filtered']);
        $this->assertSame([$old->id], $query->pluck('id')->all());
    }

    public function test_pdf_export_requires_a_month_and_scopes_the_rendered_employees_to_it(): void
    {
        $user = $this->employee();
        $this->slip($user, '2026-08');
        $this->slip($user, '2026-07');
        $service = app(SalarySlipExportService::class);
        try {
            $service->pdf(SalarySlip::query(), 'Tenant');
            $this->fail('PDF export must require a month.');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('period', $e->errors());
        }
        $document = \Mockery::mock(PDF::class);
        $document->shouldReceive('setPaper')->once()->with('a4')->andReturnSelf();
        $document->shouldReceive('output')->once()->andReturn('%PDF-test');
        \Barryvdh\DomPDF\Facade\Pdf::shouldReceive('loadView')->once()->with('filament.tenant.pages.pdf.salary-slips', \Mockery::on(fn ($data) => $data['slips']->count() === 1 && $data['slips']->first()->period_start->format('Y-m') === '2026-08'))->andReturn($document);
        $response = $service->pdf(SalarySlip::query(), 'Tenant', Carbon::parse('2026-08-01'));
        ob_start();
        $response->sendContent();
        $pdf = ob_get_clean();
        $this->assertSame('%PDF-test', $pdf);
    }

    public function test_leap_february_uses_29_calendar_days_for_proration(): void
    {
        $user = $this->employee();
        $user->payrollProfile()->create(['payroll_enabled' => true, 'basic_salary_minor' => 290000, 'effective_from' => '2024-02-01', 'employment_starts_on' => '2024-02-15']);
        $this->manualCalendar($user, '2024-02-15', '2024-02-29');
        $period = Carbon::parse('2024-02-01');
        $review = app(ManualPayrollReviewService::class);
        $review->approve($user, $this->actor, $period, 'Confirmed leap-month employment', $review->signature($user, $period));
        $slip = app(SalarySlipService::class)->generate($user, $period);
        $this->assertSame(150000, $slip->basic_salary_minor);
        $this->assertSame(29, $slip->calculation_snapshot['calendar_day_divisor']);
    }

    public function test_manual_entry_window_validation_is_visible_in_the_modal(): void
    {
        $user = $this->employee();
        $shift = $this->shift();
        Livewire::test(ListAttendanceDays::class)->callAction('manualAttendance', data: ['user_id' => $user->id, 'schedule_id' => $shift->id, 'work_date' => '2026-08-03', 'in_time' => '04:00', 'out_time' => '17:00', 'reason' => 'Outside shift'])
            ->assertHasErrors(['mountedActions.0.data.in_time']);
        $this->assertDatabaseCount('manual_attendance_entries', 0);
    }

    public function test_manual_payroll_review_action_records_the_reviewed_period(): void
    {
        $user = $this->employee();
        $user->payrollProfile()->create(['payroll_enabled' => true, 'basic_salary_minor' => 310000, 'effective_from' => '2026-08-01']);
        $this->manualCalendar($user, '2026-08-01', '2026-08-31');
        $review = app(ManualPayrollReviewService::class);
        Livewire::test(ListSalarySlips::class)->callAction('reviewManualPeriod', data: ['user_id' => $user->id, 'period' => '2026-08-01', 'evidence_signature' => $review->signature($user, Carbon::parse('2026-08-01')), 'confirmed' => true, 'reason' => 'HR verified the complete period'])
            ->assertHasNoErrors()->assertNotified('Manual payroll period reviewed');
        $this->assertTrue($review->approved($user, Carbon::parse('2026-08-01')));
    }

    public function test_upgrade_preserves_old_run_results_and_flags_missing_legacy_details(): void
    {
        $migration = require database_path('migrations/tenant/2026_10_14_120000_add_attendance_review_and_payroll_period_evidence.php');
        $migration->down();
        $kept = $this->employee('kept');
        $removed = $this->employee('old-removed');
        $known = AttendanceCalculationRun::create(['run_key' => Str::uuid(), 'from_date' => '2026-08-03', 'to_date' => '2026-08-03', 'total' => 1, 'completed' => 1, 'state' => 'completed']);
        $lost = AttendanceCalculationRun::create(['run_key' => Str::uuid(), 'from_date' => '2026-08-03', 'to_date' => '2026-08-04', 'total' => 2, 'completed' => 1, 'state' => 'running']);
        DB::table('attendance_calculation_items')->insert(['attendance_calculation_run_id' => $known->id, 'user_id' => $kept->id, 'work_date' => '2026-08-03']);
        DB::table('attendance_calculation_items')->insert(['attendance_calculation_run_id' => $lost->id, 'user_id' => $removed->id, 'work_date' => '2026-08-03']);
        $removed->delete();
        $migration->up();
        $this->assertDatabaseHas('attendance_calculation_results', ['attendance_calculation_run_id' => $known->id, 'employee_id_snapshot' => $kept->id, 'outcome' => 'completed']);
        $this->assertSame(1, $lost->fresh()->completed);
        $this->assertSame(1, $lost->fresh()->legacy_completed_credit);
        $this->assertSame('needs_reconciliation', $lost->fresh()->state);
        $this->assertFalse(AttendanceCalculationRun::where('id', $lost->id)->whereIn('state', ['running', 'queued'])->exists());
    }

    public function test_partial_month_including_only_a_verified_off_day_is_prorated(): void
    {
        $user = $this->employee();
        $user->payrollProfile()->create(['payroll_enabled' => true, 'basic_salary_minor' => 310000, 'effective_from' => '2026-08-01', 'employment_starts_on' => '2026-08-30', 'employment_ends_on' => '2026-08-30']);
        AttendanceDay::create(['user_id' => $user->id, 'work_date' => '2026-08-30', 'status' => 'off', 'source' => 'manual']);
        $period = Carbon::parse('2026-08-01');
        $review = app(ManualPayrollReviewService::class);
        $review->approve($user, $this->actor, $period, 'Confirmed eligible weekly-off employment date', $review->signature($user, $period));
        $slip = app(SalarySlipService::class)->generate($user, $period);
        $this->assertSame(10000, $slip->basic_salary_minor);
        $this->assertSame(1, $slip->calculation_snapshot['eligible_days']);
        $this->assertSame(0, $slip->attendance_deduction_minor);
    }

    public function test_manual_interval_is_not_discarded_on_a_holiday_and_duplicate_is_rejected(): void
    {
        $user = $this->employee();
        $shift = $this->shift();
        Holiday::create(['name' => 'Holiday', 'starts_on' => '2026-08-03', 'ends_on' => '2026-08-03', 'recurrence' => 'none', 'active' => true]);
        $data = ['user_id' => $user->id, 'schedule_id' => $shift->id, 'work_date' => '2026-08-03', 'in_at' => '2026-08-03 09:00', 'out_at' => '2026-08-03 17:00', 'reason' => 'Verified actual work on holiday'];
        $day = app(AttendanceEntryService::class)->add($this->actor, $data);
        $this->assertSame(480, $day->candidate_worked_minutes);
        $this->assertSame('manual', $day->source);
        $this->expectException(ValidationException::class);
        app(AttendanceEntryService::class)->add($this->actor, $data);
    }

    public function test_prior_leave_allowance_changes_invalidate_manual_period_review(): void
    {
        $user = $this->employee();
        $user->payrollProfile()->create(['payroll_enabled' => true, 'basic_salary_minor' => 310000, 'effective_from' => '2026-08-01']);
        $this->manualCalendar($user, '2026-08-01', '2026-08-31');
        $period = Carbon::parse('2026-08-01');
        $review = app(ManualPayrollReviewService::class);
        $review->approve($user, $this->actor, $period, 'Reviewed annual leave allowance and payroll', $review->signature($user, $period));
        $type = LeaveType::create(['name' => 'Paid leave', 'code' => 'PL', 'is_paid' => true, 'annual_allowance_half_units' => 2, 'deduction_method' => 'salary_day_rate']);
        EmployeeLeave::create(['user_id' => $user->id, 'leave_type_id' => $type->id, 'leave_date' => '2026-07-01', 'duration' => 'full', 'state' => 'approved']);
        $this->assertFalse($review->approved($user, $period));
    }

    public function test_stale_payroll_review_error_is_visible_in_the_review_modal(): void
    {
        $user = $this->employee();
        $user->payrollProfile()->create(['payroll_enabled' => true, 'basic_salary_minor' => 310000, 'effective_from' => '2026-08-01']);
        $this->manualCalendar($user, '2026-08-01', '2026-08-31');
        Livewire::test(ListSalarySlips::class)->callAction('reviewManualPeriod', data: ['user_id' => $user->id, 'period' => '2026-08-01', 'evidence_signature' => str_repeat('0', 64), 'confirmed' => true, 'reason' => 'Stale evidence'])
            ->assertHasErrors(['mountedActions.0.data.period'])->assertNotified('Payroll review not saved');
        $this->assertDatabaseCount('manual_payroll_reviews', 0);
    }

    public function test_cached_salary_profiles_cannot_keep_an_old_manual_review_valid(): void
    {
        $user = $this->employee();
        $user->payrollProfile()->create(['payroll_enabled' => true, 'basic_salary_minor' => 310000, 'effective_from' => '2026-08-01']);
        $this->manualCalendar($user, '2026-08-01', '2026-08-31');
        $period = Carbon::parse('2026-08-01');
        $review = app(ManualPayrollReviewService::class);
        $review->approve($user, $this->actor, $period, 'Reviewed original salary', $review->signature($user, $period));
        $user->load('payrollProfile');
        $user->payrollProfile()->update(['basic_salary_minor' => 620000]);
        $this->assertFalse($review->approved($user, $period));
        $this->expectException(ValidationException::class);
        app(SalarySlipService::class)->generate($user, $period);
    }
}
