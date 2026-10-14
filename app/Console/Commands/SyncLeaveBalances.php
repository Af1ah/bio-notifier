<?php

namespace App\Console\Commands;

use App\Models\LeaveCreditLot;
use App\Models\LeavePolicy;
use App\Models\Organisation;
use App\Models\User;
use App\Services\Leave\LeaveBalanceService;
use App\Services\Leave\LeaveSettingsService;
use App\Services\Tenancy\TenantContext;
use Carbon\Carbon;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class SyncLeaveBalances extends Command
{
    protected $signature = 'leave:sync-balances {--tenant=* : Organisation IDs or shortnames}';

    protected $description = 'Issue due tenant leave entitlements and record expired credits idempotently';

    public function handle(): int
    {
        $filters = array_filter($this->option('tenant'));
        foreach (Organisation::when($filters, fn ($q) => $q->where(fn ($q) => $q->whereIn('id', $filters)->orWhereIn('shortname', $filters)))->cursor() as $tenant) {
            TenantContext::run($tenant, function () {
                $date = Carbon::today(LeaveSettingsService::timezone());
                $service = app(LeaveBalanceService::class);
                $types = LeavePolicy::distinct()->pluck('leave_type_id');
                User::employees()->chunkById(100, function ($users) use ($types, $service, $date) {
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
                    }
                });
            });
            $this->info('Leave balances synced for '.$tenant->shortname);
        }

        return self::SUCCESS;
    }
}
