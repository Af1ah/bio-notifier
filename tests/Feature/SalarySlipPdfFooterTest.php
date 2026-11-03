<?php

namespace Tests\Feature;

use App\Models\SalarySlip;
use App\Models\User;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class SalarySlipPdfFooterTest extends TestCase
{
    public static function payBases(): array
    {
        return [['monthly', true], ['daily', false], ['hourly', false], [null, true]];
    }

    public static function sampleSlip(?string $basis): SalarySlip
    {
        $employee = new User(['name' => 'Example Employee', 'pin' => 'EXAMPLE']);
        $employee->setRelation('branch', null)->setRelation('department', null)->setRelation('taskGroups', collect());
        $snapshot = ['eligible_start' => '2026-10-01', 'eligible_end' => '2026-10-31', 'eligible_days' => 31, 'calendar_day_divisor' => 31];
        if ($basis !== null) {
            $snapshot['pay_basis'] = $basis;
        }
        $slip = new SalarySlip([
            'period_start' => '2026-10-01', 'status' => 'paid', 'calculated_at' => '2026-11-01 12:00:00', 'currency' => 'INR',
            'basic_salary_minor' => 700000, 'gross_pay_minor' => 700000, 'attendance_deduction_minor' => 11290,
            'total_deduction_minor' => 11290, 'net_pay_minor' => 688710, 'scheduled_days' => 27, 'full_days' => 26,
            'half_days' => 1, 'absent_days' => 0, 'paid_leave_half_units' => 0, 'unpaid_leave_half_units' => 0,
            'approved_overtime_minutes' => 0, 'calculation_snapshot' => $snapshot,
        ]);
        $slip->setRelation('user', $employee);

        return $slip;
    }

    #[DataProvider('payBases')]
    public function test_footer_renders_plain_text_without_blade_code(?string $basis, bool $monthly): void
    {
        $html = view('filament.tenant.pages.pdf.salary-slips', ['organisation' => 'Example Organisation', 'slips' => collect([self::sampleSlip($basis)]), 'totals' => collect()])->render();
        $text = preg_replace('/\s+/', ' ', strip_tags($html));
        $this->assertStringContainsString('Eligible pay dates: 2026-10-01–2026-10-31 · 31 calendar days', $text);
        $this->assertStringContainsString('INR 6,887.10', $text);
        foreach (['@if', '@endif', 'calculation_snapshot', '$slip'] as $raw) {
            $this->assertStringNotContainsString($raw, $text);
        }
        if ($monthly) {
            $this->assertStringContainsString('31 calendar days of 31 in this month', $text);
        } else {
            $this->assertStringNotContainsString('of 31 in this month', $text);
        }
    }
}
