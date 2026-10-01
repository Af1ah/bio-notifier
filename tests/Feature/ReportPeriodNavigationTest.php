<?php

namespace Tests\Feature;

use App\Filament\Tenant\Pages\Reports;
use App\Models\User;
use Carbon\Carbon;
use Filament\Facades\Filament;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\URL;
use Livewire\Livewire;
use Tests\TestCase;

class ReportPeriodNavigationTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Artisan::call('migrate:fresh', ['--path' => database_path('migrations/tenant'), '--realpath' => true, '--force' => true]);
        Carbon::setTestNow('2026-10-01 12:00:00');
        Filament::setCurrentPanel(Filament::getPanel('tenant'));
        URL::defaults(['tenant' => 'sec']);
        $this->actingAs(User::create(['name' => 'Report Admin', 'pin' => '950', 'privilege' => 14]));
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_daily_picker_updates_applied_and_deferred_filters(): void
    {
        Livewire::test(Reports::class)
            ->assertTableActionVisible('selectReportDate')
            ->assertTableActionHidden('previousReportWeek')
            ->callTableAction('selectReportDate', data: ['date' => '2026-08-26'])
            ->assertHasNoErrors()
            ->assertSet('tableFilters.date_range.from_date', '2026-08-26')
            ->assertSet('tableFilters.date_range.to_date', '2026-08-26')
            ->assertSet('tableDeferredFilters.date_range.from_date', '2026-08-26');
    }

    public function test_week_navigation_crosses_years_and_keeps_employee_filters(): void
    {
        Livewire::test(Reports::class)
            ->set('activeTab', 'weekly')
            ->set('tableFilters.user_ids.values', [1])
            ->callTableAction('selectReportDate', data: ['date' => '2026-01-01'])
            ->assertSet('tableFilters.date_range.from_date', '2025-12-29')
            ->assertSet('tableFilters.date_range.to_date', '2026-01-04')
            ->callTableAction('previousReportWeek')
            ->assertSet('tableFilters.date_range.from_date', '2025-12-22')
            ->callTableAction('nextReportWeek')
            ->assertSet('tableFilters.date_range.to_date', '2026-01-04')
            ->assertSet('tableFilters.user_ids.values', [1])
            ->assertSet('tableDeferredFilters.date_range.to_date', '2026-01-04');
    }

    public function test_month_picker_selects_the_whole_leap_month_and_range_filter_remains_available(): void
    {
        Livewire::test(Reports::class)
            ->set('activeTab', 'monthly')
            ->assertTableActionVisible('selectReportMonth')
            ->assertTableActionHidden('selectReportDate')
            ->callTableAction('selectReportMonth', data: ['month' => 2, 'year' => 2028])
            ->assertHasNoErrors()
            ->assertSet('tableFilters.date_range.from_date', '2028-02-01')
            ->assertSet('tableFilters.date_range.to_date', '2028-02-29')
            ->filterTable('date_range', ['from_date' => '2026-08-01', 'to_date' => '2026-09-30'])
            ->assertSet('tableFilters.date_range.from_date', '2026-08-01')
            ->assertSet('tableFilters.date_range.to_date', '2026-09-30');
    }
}
