<?php

namespace App\Services\Leave;

use App\Models\User;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

final class LeaveBalanceSummaryService
{
    public static function invalidate(?int $userId = null): void
    {
        if (! Schema::hasTable('leave_balance_revisions')) {
            return;
        }
        $scope = $userId === null ? 'global' : 'user:'.$userId;
        DB::table('leave_balance_revisions')->insertOrIgnore(['scope' => $scope, 'revision' => 0]);
        DB::table('leave_balance_revisions')->where('scope', $scope)->increment('revision');
    }

    private function revisions(User $user): array
    {
        $versions = DB::table('leave_balance_revisions')->whereIn('scope', ['global', 'user:'.$user->id])->pluck('revision', 'scope');

        return [(int) ($versions['global'] ?? 0), (int) ($versions['user:'.$user->id] ?? 0)];
    }

    public function get(User $user, int $typeId, Carbon $date, bool $rebuild = false): array
    {
        if ($typeId < 1) {
            return array_fill_keys(['available_half_units', 'reserved_half_units', 'carried_half_units', 'consumed_half_units', 'expiring_half_units'], 0);
        }
        $ledger = app(LeaveBalanceService::class);
        if (! $date->isSameDay(Carbon::today(LeaveSettingsService::timezone())) || ! Schema::hasTable('leave_balance_summaries')) {
            return $ledger->balance($user, $typeId, $date);
        }
        $versions = $this->revisions($user);
        if (! $rebuild) {
            $summary = DB::table('leave_balance_summaries')->where('user_id', $user->id)->where('leave_type_id', $typeId)
                ->whereDate('as_of', $date->toDateString())->where('global_revision', $versions[0])->where('employee_revision', $versions[1])->first();
            if ($summary) {
                return json_decode($summary->balances, true, 512, JSON_THROW_ON_ERROR);
            }
        }

        return DB::transaction(function () use ($user, $typeId, $date, $ledger): array {
            User::whereKey($user->id)->lockForUpdate()->firstOrFail();
            // Keep the pre-calculation version: a concurrent change can never publish a falsely fresh summary.
            $versions = $this->revisions($user);
            $balances = $ledger->balance($user, $typeId, $date);
            // A first entitlement grant may invalidate this account. Reconcile once after those idempotent writes.
            if ($versions !== $this->revisions($user)) {
                $versions = $this->revisions($user);
                $balances = $ledger->balance($user, $typeId, $date);
            }
            DB::table('leave_balance_summaries')->upsert([[
                'user_id' => $user->id, 'leave_type_id' => $typeId, 'as_of' => $date->toDateString(),
                'global_revision' => $versions[0], 'employee_revision' => $versions[1], 'balances' => json_encode($balances, JSON_THROW_ON_ERROR),
                'created_at' => now(), 'updated_at' => now(),
            ]], ['user_id', 'leave_type_id'], ['as_of', 'global_revision', 'employee_revision', 'balances', 'updated_at']);

            return $balances;
        }, 3);
    }
}
