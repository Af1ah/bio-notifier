<?php

namespace Tests\Feature;

use App\Filament\Tenant\Pages\LeaveBalances;
use App\Livewire\Tenant\EmployeeLeaveBalances;
use App\Models\LeaveType;
use App\Models\User;
use App\Services\Leave\EmployeeLeaveService;
use App\Services\Leave\LeaveBalanceService;
use App\Services\Leave\LeaveBalanceSummaryService;
use App\Services\Leave\LeavePolicyService;
use Carbon\Carbon;
use Filament\Facades\Filament;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\URL;
use Livewire\Livewire;
use Tests\TestCase;

class LeaveBalanceSummaryTest extends TestCase
{
    private User $actor;

    private User $user;

    private LeaveType $type;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-10-14 12:00:00');
        Artisan::call('migrate:fresh', ['--path' => database_path('migrations/tenant'), '--realpath' => true, '--force' => true]);
        $this->actor = User::create(['pin' => 'hr', 'name' => 'HR', 'privilege' => 14, 'is_system_account' => true]);
        $this->user = User::create(['pin' => 'employee', 'name' => 'Employee']);
        $this->type = LeaveType::create(['name' => 'Paid leave', 'code' => 'PL', 'is_paid' => true, 'active' => true]);
        $this->actingAs($this->actor);
        Filament::setCurrentPanel(Filament::getPanel('tenant'));
        URL::defaults(['tenant' => 'test']);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function opening(string $key, int $units, string $expiry = '2026-10-31'): void
    {
        app(LeaveBalanceService::class)->opening($this->user, $this->type, $units, Carbon::parse('2026-10-01'), Carbon::parse($expiry), 'Reviewed balance', $this->actor, $key);
    }

    public function test_warm_summary_avoids_entitlement_and_ledger_recalculation_and_updates_after_adjustment(): void
    {
        $this->opening('first', 4);
        $service = app(LeaveBalanceSummaryService::class);
        $expected = $service->get($this->user, $this->type->id, today());
        DB::enableQueryLog();
        DB::flushQueryLog();
        $this->assertSame($expected, $service->get($this->user, $this->type->id, today()));
        $queries = DB::getQueryLog();
        DB::disableQueryLog();
        $this->assertLessThanOrEqual(4, count($queries));
        foreach ($queries as $query) {
            $this->assertStringNotContainsString('leave_credit_lots', $query['query']);
            $this->assertStringNotContainsString('leave_ledger_entries', $query['query']);
        }
        $this->opening('second', 2);
        $this->assertSame(6, $service->get($this->user, $this->type->id, today())['available_half_units']);
        $this->assertSame(1, DB::table('leave_balance_summaries')->count());
    }

    public function test_hr_role_can_manage_leave_balances(): void
    {
        $this->actor->update(['privilege' => User::HR_PRIVILEGE]);
        $this->opening('hr-opening', 4);
        $this->assertSame(4, app(LeaveBalanceSummaryService::class)->get($this->user, $this->type->id, today())['available_half_units']);
    }

    public function test_new_day_expires_credits_and_historical_views_do_not_replace_today_summary(): void
    {
        $this->opening('expiry', 4, '2026-10-14');
        $service = app(LeaveBalanceSummaryService::class);
        $this->assertSame(4, $service->get($this->user, $this->type->id, today())['available_half_units']);
        Carbon::setTestNow('2026-10-15 12:00:00');
        $this->assertSame(0, $service->get($this->user, $this->type->id, today())['available_half_units']);
        $this->assertSame(4, $service->get($this->user, $this->type->id, Carbon::parse('2026-10-14'))['available_half_units']);
        $this->assertSame('2026-10-15', DB::table('leave_balance_summaries')->value('as_of'));
    }

    public function test_reservation_approval_and_cancellation_refresh_the_saved_balance(): void
    {
        app(LeavePolicyService::class)->save(null, ['name' => 'Monthly policy', 'leave_type_id' => $this->type->id, 'tenant_default' => true,
            'effective_from' => '2026-10-01', 'reason' => 'Reviewed policy', 'rules' => LeavePolicyService::defaults()], $this->actor);
        $service = app(LeaveBalanceSummaryService::class);
        $this->assertSame(4, $service->get($this->user, $this->type->id, today())['available_half_units']);
        $data = ['user_id' => $this->user->id, 'leave_type_id' => $this->type->id, 'leave_date' => '2026-10-15', 'duration' => 'full', 'state' => 'pending'];
        $leave = app(EmployeeLeaveService::class)->save(null, $data, $this->actor);
        $balance = $service->get($this->user, $this->type->id, today());
        $this->assertSame([2, 2], [$balance['available_half_units'], $balance['reserved_half_units']]);
        $data['state'] = 'approved';
        $leave = app(EmployeeLeaveService::class)->save($leave, $data, $this->actor);
        $balance = $service->get($this->user, $this->type->id, today());
        $this->assertSame([2, 0, 2], [$balance['available_half_units'], $balance['reserved_half_units'], $balance['consumed_half_units']]);
        $data['state'] = 'cancelled';
        app(EmployeeLeaveService::class)->save($leave, $data, $this->actor);
        $this->assertSame(4, $service->get($this->user, $this->type->id, today())['available_half_units']);
    }

    public function test_compact_list_and_employee_summary_share_balances_and_reject_unauthorised_access(): void
    {
        $this->opening('profile', 4);
        $page = Livewire::test(LeaveBalances::class)->assertCanSeeTableRecords([$this->user]);
        $this->assertSame(['name', 'available', 'reserved'], array_keys($page->instance()->getTable()->getColumns()));
        Livewire::test(EmployeeLeaveBalances::class, ['record' => $this->user])->assertSee('Paid leave')
            ->assertSee('Available days')->assertSee('Reserved days')->call('showHistory', $this->type->id)->assertSee('Reviewed balance');
        $this->actingAs($this->user);
        Livewire::test(EmployeeLeaveBalances::class, ['record' => $this->user])->assertForbidden();
    }

    public function test_source_changes_invalidate_only_the_employee_or_the_relevant_organisation(): void
    {
        $this->opening('source', 4);
        $service = app(LeaveBalanceSummaryService::class);
        $service->get($this->user, $this->type->id, today());
        $before = DB::table('leave_balance_summaries')->first();
        $this->user->update(['name' => 'Changed employee']);
        $service->get($this->user, $this->type->id, today());
        $after = DB::table('leave_balance_summaries')->first();
        $this->assertGreaterThan($before->employee_revision, $after->employee_revision);
        LeaveBalanceSummaryService::invalidate();
        $service->get($this->user, $this->type->id, today());
        $this->assertGreaterThan($after->global_revision, DB::table('leave_balance_summaries')->value('global_revision'));
    }

    public function test_rolled_back_adjustment_preserves_the_saved_balance(): void
    {
        $this->opening('before-rollback', 4);
        $service = app(LeaveBalanceSummaryService::class);
        $service->get($this->user, $this->type->id, today());
        try {
            DB::transaction(function (): void {
                $this->opening('rolled-back', 2);
                throw new \RuntimeException('Rollback test');
            });
        } catch (\RuntimeException $exception) {
            $this->assertSame('Rollback test', $exception->getMessage());
        }
        $this->assertSame(4, $service->get($this->user, $this->type->id, today())['available_half_units']);
    }
}
