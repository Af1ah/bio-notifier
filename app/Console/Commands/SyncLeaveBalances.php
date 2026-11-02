<?php

namespace App\Console\Commands;

use App\Models\LeaveCreditLot;
use App\Models\LeavePolicy;
use App\Models\LeaveType;
use App\Models\Organisation;
use App\Models\User;
use App\Services\Leave\LeaveBalanceService;
use App\Services\Leave\LeaveBalanceSummaryService;
use App\Services\Leave\LeaveSettingsService;
use App\Services\Tenancy\TenantContext;
use Carbon\Carbon;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class SyncLeaveBalances extends Command
{
    protected $signature = 'leave:sync-balances {--tenant=* : Organisation IDs or shortnames} {--rebuild : Rebuild saved leave summaries from the ledger}';

    protected $description = 'Refresh leave summaries, issue due entitlements and record expired credits idempotently';

    public function handle(): int
    {
        $filters = array_filter($this->option('tenant'));
        foreach (Organisation::when($filters, fn ($q) => $q->where(fn ($q) => $q->whereIn('id', $filters)->orWhereIn('shortname', $filters)))->cursor() as $tenant) {
            TenantContext::run($tenant, function () {
                $date = Carbon::today(LeaveSettingsService::timezone());
                $service = app(LeaveBalanceService::class);
                $types = LeavePolicy::distinct()->pluck('leave_type_id');
                $summaryTypes = LeaveType::where('active', true)->where('is_paid', true)->pluck('id');
                User::employees()->chunkById(100, function ($users) use ($types, $summaryTypes, $service, $date) {
                    foreach ($users as $user) {
                        foreach ($types as $type) {
                            $service->ensureGrants($user, $type, $date);
                        }
                        DB::transaction(function () use ($user, $service, $date) {
                            User::lockForUpdate()->findOrFail($user->id);
                            foreach (LeaveCreditLot::where('user_id', $user->id)->whereDate('expires_on', '<', $date)->get() as $lot) {
                                // Expiry is enforced by the lot date even if this job is delayed.
                                $service->entry($lot, 'expiry', -$service->remaining($lot, $lot->expires_on), $lot->expires_on->copy()->addDay(), 'expiry:'.$lot->id, 'Unused credit expired');
                            }
                        });
                        foreach ($summaryTypes as $typeId) {
                            app(LeaveBalanceSummaryService::class)->get($user, $typeId, $date, (bool) $this->option('rebuild'));
                        }
                    }
                });
            });
            $this->info('Leave balances synced for '.$tenant->shortname);
        }

        return self::SUCCESS;
    }
}
