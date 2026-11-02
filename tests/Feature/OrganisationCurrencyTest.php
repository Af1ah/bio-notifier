<?php

namespace Tests\Feature;

use App\Models\Organisation;
use App\Models\PayrollTemplate;
use App\Models\SalarySlip;
use App\Models\Schedule;
use App\Models\User;
use App\Services\Attendance\ShiftRuleService;
use App\Services\Leave\LeavePolicyService;
use App\Services\Payroll\PayrollProfileHistoryService;
use App\Services\Support\TenantCurrency;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class OrganisationCurrencyTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        config(['tenancy.database.central_connection' => 'sqlite']);
        Artisan::call('migrate:fresh', ['--path' => database_path('migrations/tenant'), '--realpath' => true, '--force' => true]);
        Schema::create('organisations', function (Blueprint $table): void {
            $table->string('id')->primary();
            $table->string('name');
            $table->string('shortname');
            $table->json('data')->nullable();
            $table->timestamps();
        });
        DB::table('organisations')->insert(['id' => 'currency-test', 'name' => 'Currency test', 'shortname' => 'currency-test', 'data' => json_encode(['currency' => 'AED'])]);
        tenancy()->initialized = true;
        tenancy()->tenant = Organisation::findOrFail('currency-test');
    }

    protected function tearDown(): void
    {
        tenancy()->initialized = false;
        tenancy()->tenant = null;
        parent::tearDown();
    }

    public function test_currency_is_persisted_and_isolated_between_organisations(): void
    {
        $this->assertSame('AED', TenantCurrency::get());
        tenant()->update(['currency' => 'USD']);
        $this->assertSame('USD', tenant()->fresh()->currency);
        $this->assertSame('USD', TenantCurrency::get());
        $this->assertSame('USD 123.45', TenantCurrency::format(12345));
        tenancy()->tenant = (new Organisation)->forceFill(['id' => 'other', 'currency' => 'EUR']);
        $this->assertSame('EUR', TenantCurrency::get());
        tenancy()->tenant = null;
        $this->assertSame('INR', TenantCurrency::get());
    }

    public function test_unsupported_currency_is_rejected_at_the_model_boundary(): void
    {
        $this->expectException(ValidationException::class);
        tenant()->update(['currency' => 'BAD']);
    }

    public function test_payroll_inherits_currency_and_currency_changes_preserve_revisions_and_saved_slips(): void
    {
        $user = User::create(['pin' => 'currency', 'name' => 'Currency employee']);
        $profile = $user->payrollProfile()->create(['payroll_enabled' => true, 'pay_basis' => 'monthly', 'currency' => 'GBP', 'basic_salary_minor' => 800000, 'effective_from' => '2026-08-01']);
        app(PayrollProfileHistoryService::class)->afterSave($profile, null);
        $this->assertSame('AED', $profile->fresh()->currency);
        $slip = SalarySlip::create(['user_id' => $user->id, 'period_start' => '2026-08-01', 'period_end' => '2026-08-31', 'currency' => 'AED', 'status' => 'paid', 'net_pay_minor' => 800000, 'calculation_snapshot' => [], 'calculated_at' => now()]);

        tenant()->update(['currency' => 'USD']);
        $this->assertSame('AED', $profile->fresh()->currency);
        $profile->effective_from = '2026-09-01';
        $profile->save();
        app(PayrollProfileHistoryService::class)->afterSave($profile, null);

        $this->assertSame('USD', $profile->fresh()->currency);
        $this->assertSame('AED', app(PayrollProfileHistoryService::class)->forDate($user, '2026-08-15')->currency);
        $this->assertSame('USD', app(PayrollProfileHistoryService::class)->forDate($user, '2026-09-15')->currency);
        $this->assertSame('AED', $slip->fresh()->currency);
        $this->assertSame(800000, $slip->fresh()->net_pay_minor);
    }

    public function test_templates_and_new_shift_rules_inherit_currency_without_rewriting_old_revisions(): void
    {
        $template = PayrollTemplate::create(['name' => 'Default', 'effective_from' => '2026-08-01', 'settings' => array_fill_keys(PayrollProfileHistoryService::MONEY, 0) + ['pay_basis' => 'monthly', 'currency' => 'GBP']]);
        $this->assertSame('AED', $template->fresh()->settings['currency']);
        $shift = Schedule::create(['name' => 'Currency shift', 'type' => 'regular', 'valid_from' => '2026-08-01', 'status' => true, 'rules' => ['weekdays' => [1, 2, 3, 4, 5], 'overtime_currency' => 'GBP']]);
        $old = app(ShiftRuleService::class)->sync($shift);
        $this->assertSame('AED', $old->snapshot['overtime_currency']);
        $this->assertSame('AED', LeavePolicyService::defaults()['comp_payment_currency']);

        tenant()->update(['currency' => 'USD']);
        $shift->valid_from = '2026-09-01';
        $shift->save();
        $new = app(ShiftRuleService::class)->sync($shift);
        $this->assertSame('USD', $new->snapshot['overtime_currency']);
        $this->assertSame('AED', $old->fresh()->snapshot['overtime_currency']);
    }
}
