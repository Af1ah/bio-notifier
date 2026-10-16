<?php

namespace App\Services\Leave;

use App\Models\CompensatoryAward;
use App\Models\EmployeeLeave;
use App\Models\LeaveCreditLot;
use App\Models\LeaveLedgerEntry;
use App\Models\LeavePolicyRevision;
use App\Models\LeaveType;
use App\Models\User;
use Carbon\Carbon;
use Carbon\CarbonPeriod;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class LeaveBalanceService
{
    public function ensureGrants(User $user, int $typeId, Carbon $through): void
    {
        DB::transaction(function () use ($user, $typeId, $through) {
            User::lockForUpdate()->findOrFail($user->id);
            $first = LeavePolicyRevision::whereIn('leave_policy_id', DB::table('leave_policies')->where('leave_type_id', $typeId)->pluck('id'))->min('effective_from');
            if (! $first) {
                return;
            }
            $limit = Carbon::parse($through->toDateString())->startOfDay()
                ->min(Carbon::parse(Carbon::today(LeaveSettingsService::timezone())->toDateString())->startOfDay());
            $profile = $user->payrollProfile()->first();
            foreach (CarbonPeriod::create(Carbon::parse($first)->startOfMonth(), '1 month', $limit->copy()->startOfMonth()) as $month) {
                // A mid-month enrolment is eligible for the remaining calendar days.
                $probe = $month->copy()->endOfMonth()->startOfDay()->min($limit);
                $revision = app(LeavePolicyService::class)->resolve($user, $typeId, $probe);
                if (! $revision || ! $revision->rules['enabled'] || $revision->rules['mode'] === 'compensatory') {
                    continue;
                }
                $r = $revision->rules;
                $windowStart = empty($r['leave_period_from']) ? null : Carbon::parse($r['leave_period_from'])->startOfDay();
                $windowEnd = empty($r['leave_period_to']) ? null : Carbon::parse($r['leave_period_to'])->startOfDay();
                if (($windowStart && $month->copy()->endOfMonth()->lt($windowStart)) || ($windowEnd && $month->gt($windowEnd))) {
                    continue;
                }
                $periodStart = $r['mode'] === 'annual' ? ($windowStart?->copy() ?? $month->copy()->startOfYear()) : $month->copy();
                $nominalEnd = $r['mode'] === 'annual' ? ($windowEnd?->copy() ?? $month->copy()->endOfYear()->startOfDay()) : $month->copy()->endOfMonth()->startOfDay();
                $periodEnd = $windowEnd ? $nominalEnd->copy()->min($windowEnd) : $nominalEnd->copy();
                $start = $periodStart->copy()->max($revision->effective_from);
                if ($windowStart) {
                    $start = $start->max($windowStart);
                }
                $assignment = DB::table('employee_leave_policy_assignments')->where('user_id', $user->id)->where('leave_policy_id', $revision->leave_policy_id)->whereDate('starts_on', '<=', $probe)->where(fn ($q) => $q->whereNull('ends_on')->orWhereDate('ends_on', '>=', $probe))->first();
                if ($assignment) {
                    $start = $start->max(Carbon::parse($assignment->starts_on));
                }
                $end = $periodEnd->copy();
                if ($profile?->employment_starts_on) {
                    $start = $start->max($profile->employment_starts_on);
                }
                if ($profile?->employment_ends_on) {
                    $end = $end->min($profile->employment_ends_on);
                }
                if ($assignment?->ends_on) {
                    $end = $end->min(Carbon::parse($assignment->ends_on));
                }
                if ($start->gt($end)) {
                    continue;
                }
                // Rollover is due at the period boundary, even for end-of-period grants.
                $boundary = $periodStart->copy()->max($start);
                if ($boundary->lte($limit)) {
                    $this->applyCarryCap($user, $typeId, $boundary, $r);
                }
                $available = $r['grant_timing'] === 'end' ? $end : $start;
                if ($available->gt($limit)) {
                    continue;
                }
                $key = "grant:{$user->id}:{$typeId}:".$periodStart->toDateString();
                if (LeaveCreditLot::with('policyRevision')->where('source_key', $key)->exists()) {
                    continue;
                }
                $units = (int) $r['grant_half_units'];
                if ($r['prorate_join_exit']) {
                    $units = intdiv($units * ((int) $start->diffInDays($end) + 1), (int) $periodStart->diffInDays($nominalEnd) + 1);
                }
                $existing = LeaveCreditLot::with('policyRevision')->where('user_id', $user->id)->where('leave_type_id', $typeId)
                    ->where('source_kind', 'entitlement')->whereDate('available_on', '<=', $available)
                    ->whereDate('expires_on', '>=', $available)->get()->sum(fn ($lot) => $this->remaining($lot, $available));
                $units = min($units, max(0, (int) $r['balance_cap_half_units'] - $existing));

                $expiry = $r['carry_enabled'] ? $periodEnd->copy()->addMonthsNoOverflow((int) $r['expiry_months'])->endOfMonth()->startOfDay() : $periodEnd->copy();
                if ($r['carry_enabled'] && ! empty($r['fixed_expiry_month'])) {
                    $expiry = Carbon::create($periodEnd->year, $r['fixed_expiry_month'], 1)->startOfDay();
                    $expiry->day(min((int) $r['fixed_expiry_day'], $expiry->daysInMonth));
                    if ($expiry->lt($periodEnd)) {
                        $expiry = Carbon::create($periodEnd->year + 1, $r['fixed_expiry_month'], 1)->startOfDay();
                        $expiry->day(min((int) $r['fixed_expiry_day'], $expiry->daysInMonth));
                    }
                }
                if ($r['carry_enabled'] && ! empty($r['reset_dates'])) {
                    $expiry = app(LeaveExpiryService::class)->beforeNextReset($available, $r['reset_dates']);
                }
                if ($r['carry_enabled'] && array_key_exists('carry_roll_again', $r) && ! $r['carry_roll_again']) {
                    $expiry = $expiry->min($periodEnd->copy()->addMonthNoOverflow()->endOfMonth()->startOfDay());
                }
                $lot = LeaveCreditLot::create(['user_id' => $user->id, 'leave_type_id' => $typeId, 'leave_policy_revision_id' => $revision->id,
                    'granted_half_units' => $units, 'available_on' => $available, 'expires_on' => $expiry,
                    'source_kind' => 'entitlement', 'source_key' => $key, 'evidence' => ['period_start' => $periodStart->toDateString(), 'period_end' => $periodEnd->toDateString(), 'eligible_start' => $start->toDateString(), 'eligible_end' => $end->toDateString(), 'rules' => $r]]);
                $this->entry($lot, 'grant', $units, $available, $key, 'Scheduled entitlement');
            }
        }, 3);
    }

    private function applyCarryCap(User $user, int $typeId, Carbon $boundary, array $rules): void
    {
        $lots = LeaveCreditLot::with('policyRevision')->where('user_id', $user->id)->where('leave_type_id', $typeId)->where('source_kind', 'entitlement')
            ->whereDate('available_on', '<', $boundary)->whereDate('expires_on', '>=', $boundary)->orderBy('expires_on')->orderBy('id')->get();
        $remaining = $rules['carry_enabled'] ? (int) $rules['carry_cap_half_units'] : 0;
        foreach ($lots as $lot) {
            $key = "carry:{$lot->id}:".$boundary->toDateString();
            if (LeaveLedgerEntry::where('idempotency_key', $key)->exists()) {
                continue;
            }
            $units = $this->remaining($lot, $boundary);
            $keep = min($remaining, $units);
            $remaining -= $keep;
            $this->entry($lot, 'carry_limit', -($units - $keep), $boundary, $key, 'Carry-forward cap applied; original expiry retained');
        }
    }

    public function remaining(LeaveCreditLot $lot, ?Carbon $asOf = null): int
    {
        if ($lot->leave_policy_revision_id && ! $lot->policyRevision?->leave_policy_id) {
            return 0;
        }
        if ($lot->source_kind === 'compensatory') {
            $award = CompensatoryAward::find($lot->evidence['award_id'] ?? null);
            if (! $award || $award->state !== 'approved' || ! hash_equals($award->evidence_signature, app(CompensatoryLeaveService::class)->signature($award->day))) {
                return 0;
            }
        }
        // Reservations cover the whole account, including future approved bookings.
        $entries = $lot->entries()->whereNotIn('kind', ['grant', 'expiry'])
            ->when($asOf, fn ($q) => $q->where(fn ($q) => $q->whereIn('kind', ['reserve', 'consume', 'release'])->orWhereDate('effective_on', '<=', $asOf)))
            ->sum('half_units');

        return max(0, (int) $lot->granted_half_units + (int) $entries);
    }

    public function availableUnits(User $user, int $typeId, Carbon $date): int
    {
        return LeaveCreditLot::with('policyRevision')->where('user_id', $user->id)->where('leave_type_id', $typeId)->whereDate('available_on', '<=', $date)->whereDate('expires_on', '>=', $date)->get()->sum(fn ($lot) => $this->remaining($lot, $date));
    }

    public function balance(User $user, int $typeId, Carbon $date): array
    {
        $this->ensureGrants($user, $typeId, $date);
        $lots = LeaveCreditLot::with('policyRevision')->where('user_id', $user->id)->where('leave_type_id', $typeId)->whereDate('available_on', '<=', $date)->orderBy('expires_on')->get();
        $eligible = $lots->filter(fn ($lot) => $lot->expires_on->gte($date->copy()->startOfDay()));
        $ids = $lots->pluck('id');

        return ['available_half_units' => $eligible->sum(fn ($lot) => $this->remaining($lot, $date)),
            'expiring_half_units' => $eligible->filter(fn ($lot) => $lot->expires_on->lte($date->copy()->addDays(LeaveSettingsService::expiryWarningDays())))->sum(fn ($lot) => $this->remaining($lot, $date)),
            'carried_half_units' => $eligible->filter(fn ($lot) => $lot->source_kind === 'entitlement' && ! empty($lot->evidence['period_end']) && Carbon::parse($lot->evidence['period_end'])->lt($date->copy()->startOfDay()))->sum(fn ($lot) => $this->remaining($lot, $date)),
            'reserved_half_units' => (int) DB::table('leave_allocations')->whereIn('leave_credit_lot_id', $ids)->where('state', 'reserved')->sum('half_units'),
            'consumed_half_units' => (int) DB::table('leave_allocations')->whereIn('leave_credit_lot_id', $ids)->where('state', 'consumed')->sum('half_units')];
    }

    public function allocate(EmployeeLeave $leave, User $actor): void
    {
        $user = User::findOrFail($leave->user_id);
        $date = $leave->leave_date;
        $revision = app(LeavePolicyService::class)->resolve($user, $leave->leave_type_id, $date);
        if (! $revision) {
            return;
        }
        $this->ensureGrants($user, $leave->leave_type_id, $date);
        $units = $leave->half_units;
        $lots = LeaveCreditLot::with('policyRevision')->where('user_id', $user->id)->where('leave_type_id', $leave->leave_type_id)
            ->whereDate('available_on', '<=', $date)->whereDate('expires_on', '>=', $date)->orderBy('expires_on')->orderBy('available_on')->orderBy('id')->get();
        foreach ($lots as $lot) {
            $take = min($units, $this->remaining($lot));
            if ($take && $lot->source_kind === 'compensatory') {
                $award = CompensatoryAward::findOrFail($lot->evidence['award_id']);
                app(CompensatoryLeaveService::class)->assertCurrent($award);
            }
            if (! $take) {
                continue;
            }
            $state = $leave->state === 'pending' ? 'reserved' : 'consumed';
            $id = DB::table('leave_allocations')->insertGetId(['employee_leave_id' => $leave->id, 'leave_credit_lot_id' => $lot->id, 'half_units' => $take, 'state' => $state, 'created_at' => now(), 'updated_at' => now()]);
            $this->entry($lot, $state === 'reserved' ? 'reserve' : 'consume', -$take, $date, "allocate:{$id}", 'Leave '.$leave->id, $actor);
            $units -= $take;
        }
        if ($units && ! $revision->rules['allow_unpaid_excess']) {
            throw ValidationException::withMessages(['leave_date' => 'Insufficient unexpired leave balance. Reduce duration, choose unpaid leave, or add a reviewed opening balance.']);
        }
    }

    public function release(EmployeeLeave $leave, User $actor): void
    {
        foreach (DB::table('leave_allocations')->where('employee_leave_id', $leave->id)->whereIn('state', ['reserved', 'consumed'])->get() as $allocation) {
            $lot = LeaveCreditLot::findOrFail($allocation->leave_credit_lot_id);
            DB::table('leave_allocations')->where('id', $allocation->id)->update(['state' => 'released', 'updated_at' => now()]);
            $this->entry($lot, 'release', $allocation->half_units, $leave->leave_date, 'release:'.$allocation->id, 'Cancelled or replaced leave; expiry unchanged', $actor);
        }
    }

    public function entry(LeaveCreditLot $lot, string $kind, int $units, Carbon $date, string $key, string $reason, ?User $actor = null): void
    {
        LeaveLedgerEntry::firstOrCreate(['idempotency_key' => $key], ['leave_credit_lot_id' => $lot->id, 'kind' => $kind, 'half_units' => $units, 'effective_on' => $date->toDateString(), 'reason' => $reason, 'actor_id' => $actor?->id]);
    }

    public function opening(User $user, LeaveType $type, int $units, Carbon $available, Carbon $expiry, string $reason, User $actor, string $requestKey): LeaveCreditLot
    {
        abort_unless((int) $actor->privilege === 14, 403);
        if ($units < 1 || $units > 1464 || $expiry->lt($available) || trim($reason) === '' || strlen($requestKey) > 100 || trim($requestKey) === '' || strlen($reason) > 1000 || ! $type->is_paid) {
            throw ValidationException::withMessages(['reason' => 'Provide a valid balance, date range and review reason.']);
        }

        return DB::transaction(function () use ($user, $type, $units, $available, $expiry, $reason, $actor, $requestKey) {
            User::lockForUpdate()->findOrFail($user->id);
            $lot = LeaveCreditLot::firstOrCreate(['source_key' => 'opening:'.$user->id.':'.$requestKey], ['user_id' => $user->id, 'leave_type_id' => $type->id, 'granted_half_units' => $units, 'available_on' => $available, 'expires_on' => $expiry, 'source_kind' => 'opening', 'evidence' => ['reason' => $reason, 'reviewer_id' => $actor->id]]);
            $this->entry($lot, 'grant', $lot->granted_half_units, $available, $lot->source_key, $reason, $actor);

            return $lot;
        }, 3);
    }

    public function reduce(LeaveCreditLot $lot, int $units, string $reason, User $actor, string $requestKey): void
    {
        abort_unless((int) $actor->privilege === 14, 403);
        if ($units < 1 || trim($reason) === '' || trim($requestKey) === '') {
            throw ValidationException::withMessages(['reason' => 'Provide a positive reduction and review reason.']);
        }
        DB::transaction(function () use ($lot, $units, $reason, $actor, $requestKey) {
            User::lockForUpdate()->findOrFail($lot->user_id);
            if (LeaveLedgerEntry::where('idempotency_key', 'adjust:'.$lot->id.':'.$requestKey)->exists()) {
                return;
            }
            if ($lot->source_kind === 'compensatory') {
                throw ValidationException::withMessages(['reason' => 'Reverse compensatory awards on the Compensatory Leave page.']);
            }
            if ($units > $this->remaining($lot)) {
                throw ValidationException::withMessages(['reason' => 'Reduction exceeds unreserved, unconsumed credit. Reconcile leave allocations first.']);
            }
            $this->entry($lot, 'adjustment', -$units, Carbon::today(LeaveSettingsService::timezone()), 'adjust:'.$lot->id.':'.$requestKey, $reason, $actor);
        }, 3);
    }

    public function payrollCoverage(EmployeeLeave $leave): ?int
    {
        if (! $leave->balance_managed) {
            $revision = app(LeavePolicyService::class)->resolve(User::findOrFail($leave->user_id), $leave->leave_type_id, $leave->leave_date);
            if ($revision) {
                throw ValidationException::withMessages(['leave' => 'Approved leave requires balance reconciliation before payroll. Open and approve the leave through the balance workflow.']);
            }

            return null;
        }
        foreach (DB::table('leave_allocations')->where('employee_leave_id', $leave->id)->where('state', 'consumed')->get() as $a) {
            $lot = LeaveCreditLot::findOrFail($a->leave_credit_lot_id);
            if ($lot->source_kind === 'compensatory') {
                app(CompensatoryLeaveService::class)->assertCurrent(CompensatoryAward::findOrFail($lot->evidence['award_id']));
            }
        }

        return (int) DB::table('leave_allocations')->where('employee_leave_id', $leave->id)->where('state', 'consumed')->sum('half_units');
    }

    public function payrollEvidence(User $user, Carbon $start, Carbon $end): array
    {
        $ids = EmployeeLeave::where('user_id', $user->id)->whereDate('leave_date', '>=', $start)->whereDate('leave_date', '<=', $end)->pluck('id');

        return ['allocations' => DB::table('leave_allocations')->whereIn('employee_leave_id', $ids)->orderBy('id')->get()->map(fn ($row) => (array) $row)->all(),
            'awards' => DB::table('compensatory_awards')->where('user_id', $user->id)->whereDate('work_date', '>=', $start)->whereDate('work_date', '<=', $end)->orderBy('id')->get()->map(fn ($row) => (array) $row)->all()];
    }
}
