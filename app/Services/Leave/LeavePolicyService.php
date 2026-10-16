<?php

namespace App\Services\Leave;

use App\Models\LeavePolicy;
use App\Models\LeavePolicyRevision;
use App\Models\LeaveType;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

class LeavePolicyService
{
    public static function defaults(): array
    {
        return ['enabled' => true, 'mode' => 'monthly', 'grant_half_units' => 4, 'grant_timing' => 'start',
            'prorate_join_exit' => true, 'carry_enabled' => true, 'carry_cap_half_units' => 12,
            'leave_period_from' => null, 'leave_period_to' => null, 'balance_cap_half_units' => 24, 'expiry_months' => 3, 'fixed_expiry_month' => null, 'fixed_expiry_day' => null,
            'allow_unpaid_excess' => false, 'unpaid_excess_deduction_method' => 'salary_day_rate', 'unpaid_excess_deduction_minor' => 0, 'comp_enabled' => false, 'comp_holiday' => true, 'comp_off' => true,
            'comp_expiry_days' => 90, 'comp_expiry_mode' => 'days', 'reset_dates' => [],
            'comp_same_day' => false, 'comp_allow_overtime' => false, 'comp_reward' => 'choice', 'comp_payment_currency' => 'INR', 'comp_payment_full_minor' => 0];
    }

    public function save(?LeavePolicy $policy, array $data, User $actor): LeavePolicy
    {
        abort_unless((int) $actor->privilege === 14, 403);
        $rules = array_replace(self::defaults(), $data['rules'] ?? []);
        // These choices now have one source: shift rules and the explicit expiry rule.
        unset($rules['carry_roll_again'], $rules['comp_half_minutes'], $rules['comp_full_minutes']);
        if (! empty($rules['annual_cutoff_date']) && ($rules['expiry_method'] ?? 'fixed') === 'fixed' && $rules['carry_enabled'] && $rules['mode'] !== 'compensatory') {
            Validator::make(['rules' => $rules], ['rules.annual_cutoff_date' => 'required|date_format:Y-m-d'])->validate();
            $cutoff = Carbon::parse($rules['annual_cutoff_date']);
            $rules['fixed_expiry_month'] = $cutoff->month;
            $rules['fixed_expiry_day'] = $cutoff->day;
        }
        if (($rules['expiry_method'] ?? null) === 'fixed' && $rules['carry_enabled'] && $rules['mode'] !== 'compensatory'
            && empty($rules['annual_cutoff_date']) && empty($rules['fixed_expiry_month'])) {
            throw ValidationException::withMessages(['rules.annual_cutoff_date' => 'Choose an annual expiry cutoff date.']);
        }
        unset($rules['annual_cutoff_date']);
        if (($rules['expiry_method'] ?? null) !== 'resets' && array_key_exists('expiry_method', $rules)) {
            $rules['reset_dates'] = [];
        }
        if (! empty($rules['reset_dates'])) {
            $rules['fixed_expiry_month'] = $rules['fixed_expiry_day'] = null;
        }
        if (($rules['expiry_method'] ?? null) === 'relative') {
            $rules['fixed_expiry_month'] = $rules['fixed_expiry_day'] = null;
        }
        if (($rules['expiry_method'] ?? null) === 'resets' && empty($rules['reset_dates'])) {
            throw ValidationException::withMessages(['rules.reset_dates' => 'Add at least one reset date.']);
        }
        unset($rules['expiry_method']);
        if ($rules['mode'] === 'compensatory') {
            $rules['leave_period_from'] = $rules['leave_period_to'] = null;
            $rules = array_replace($rules, ['grant_half_units' => 0, 'grant_timing' => 'start', 'prorate_join_exit' => false,
                'carry_enabled' => false, 'carry_cap_half_units' => 0, 'balance_cap_half_units' => 0, 'comp_enabled' => true]);
        }
        if (! $rules['carry_enabled']) {
            $rules['reset_dates'] = [];
            $rules['expiry_months'] = 0;
            $rules['fixed_expiry_month'] = $rules['fixed_expiry_day'] = null;
        }
        if (! $rules['comp_enabled']) {
            foreach (self::defaults() as $key => $value) {
                if (str_starts_with($key, 'comp_')) {
                    $rules[$key] = $value;
                }
            }
        }
        if (! $rules['allow_unpaid_excess']) {
            $rules['unpaid_excess_deduction_method'] = null;
            $rules['unpaid_excess_deduction_minor'] = null;
        }
        $data['rules'] = $rules;
        $v = Validator::make($data, [
            'name' => 'required|string|max:120', 'leave_type_id' => 'required|exists:leave_types,id',
            'tenant_default' => 'required|boolean', 'effective_from' => 'required|date', 'reason' => 'required|string|max:1000',
            'rules.enabled' => 'required|boolean', 'rules.mode' => 'required|in:monthly,annual,compensatory',
            'rules.grant_half_units' => 'required|integer|min:0|max:732', 'rules.grant_timing' => 'required|in:start,end',
            'rules.prorate_join_exit' => 'required|boolean', 'rules.carry_enabled' => 'required|boolean',
            'rules.carry_cap_half_units' => 'required|integer|min:0|max:732', 'rules.balance_cap_half_units' => 'required|integer|min:0|max:1464',
            'rules.leave_period_from' => 'nullable|required_with:rules.leave_period_to|date_format:Y-m-d',
            'rules.leave_period_to' => 'nullable|required_with:rules.leave_period_from|date_format:Y-m-d|after_or_equal:rules.leave_period_from',
            'rules.reset_dates' => 'array|max:12',
            'rules.reset_dates.*.date' => 'required|date_format:Y-m-d|distinct',
            'rules.comp_expiry_mode' => 'required|in:days,work_month_end',
            'rules.expiry_months' => 'required|integer|min:0|max:24',
            'rules.fixed_expiry_month' => 'nullable|integer|min:1|max:12', 'rules.fixed_expiry_day' => 'nullable|integer|min:1|max:31',
            'rules.unpaid_excess_deduction_method' => 'nullable|required_if:rules.allow_unpaid_excess,true|in:salary_day_rate,basic_salary_31_day_rate,fixed_amount',
            'rules.unpaid_excess_deduction_minor' => 'nullable|integer|min:0|max:100000000',
            'rules.allow_unpaid_excess' => 'required|boolean', 'rules.comp_enabled' => 'required|boolean',
            'rules.comp_holiday' => 'required|boolean', 'rules.comp_off' => 'required|boolean',
            'rules.comp_expiry_days' => 'required|integer|min:0|max:730', 'rules.comp_same_day' => 'required|boolean',
            'rules.comp_allow_overtime' => 'required|boolean',
            'rules.comp_payment_currency' => 'required|string|size:3|regex:/^[A-Z]{3}$/',
            'rules.comp_reward' => 'required|in:choice,leave,payment,both', 'rules.comp_payment_full_minor' => 'required|integer|min:0|max:100000000',
        ])->validate();
        $resetKeys = array_map(fn ($row) => Carbon::parse($row['date'])->format('m-d'), $v['rules']['reset_dates']);
        if (count(array_unique($resetKeys)) !== count($resetKeys)) {
            throw ValidationException::withMessages(['rules.reset_dates' => 'Reset month and day must be unique.']);
        }
        if (empty($v['rules']['fixed_expiry_month']) !== empty($v['rules']['fixed_expiry_day'])) {
            throw ValidationException::withMessages(['rules.fixed_expiry_day' => 'Set both cutoff month and day, or neither.']);
        }

        if (! empty($v['rules']['leave_period_to']) && Carbon::parse($v['effective_from'])->toDateString() > $v['rules']['leave_period_to']) {
            throw ValidationException::withMessages(['rules.leave_period_to' => 'The leave period must include the policy effective date. Choose the next period for a later revision.']);
        }
        $type = LeaveType::findOrFail($v['leave_type_id']);
        if (! $type->is_paid) {
            throw ValidationException::withMessages(['leave_type_id' => 'Balance policies require a paid leave type. Unpaid leave remains separate.']);
        }

        if ($v['rules']['allow_unpaid_excess'] && $v['rules']['unpaid_excess_deduction_method'] === 'fixed_amount' && (int) $v['rules']['unpaid_excess_deduction_minor'] <= 0) {
            throw ValidationException::withMessages(['rules.unpaid_excess_deduction_minor' => 'Set a positive deduction for unpaid excess leave.']);
        }

        return DB::transaction(function () use ($policy, $v, $actor) {
            // Serialize policy creation/revision across administrators.
            User::where('privilege', 14)->orderBy('id')->lockForUpdate()->get();
            $date = Carbon::parse($v['effective_from'])->startOfDay();
            if ($policy) {
                $policy = LeavePolicy::lockForUpdate()->findOrFail($policy->id);
                if ((int) $policy->leave_type_id !== (int) $v['leave_type_id'] || (bool) $policy->tenant_default !== (bool) $v['tenant_default']) {
                    throw ValidationException::withMessages(['leave_type_id' => 'A policy keeps its leave type and scope. Create a new policy for a different scope.']);
                }
                if ($date->lte(Carbon::today(LeaveSettingsService::timezone()))) {
                    throw ValidationException::withMessages(['effective_from' => 'Policy revisions must take effect in a future entitlement period; issued decisions are retained.']);
                }
                $latest = $policy->revisions()->orderByDesc('effective_from')->first();
                if ($latest && $date->lte($latest->effective_from)) {
                    throw ValidationException::withMessages(['effective_from' => 'A new revision must start after the latest revision.']);
                }
                $annualStart = $v['rules']['leave_period_from'] ?? null;
                $wrongAnnualBoundary = $annualStart ? $date->toDateString() !== $annualStart : $date->format('m-d') !== '01-01';
                if ($latest && ($latest->rules['mode'] === 'annual' ? $wrongAnnualBoundary : ($latest->rules['mode'] === 'monthly' && $date->day !== 1))) {
                    throw ValidationException::withMessages(['effective_from' => 'After initial enrolment, revisions begin at month start or the annual leave-period start (1 January when no period is set).']);
                }
                $lastGrant = DB::table('leave_credit_lots')->whereIn('leave_policy_revision_id', $policy->revisions()->pluck('id'))->max('available_on');
                if ($lastGrant && $date->lte(Carbon::parse($lastGrant))) {
                    throw ValidationException::withMessages(['effective_from' => 'Existing credits are immutable. Start the revision after already issued credits.']);
                }
            } elseif ($v['tenant_default'] && LeavePolicy::where('leave_type_id', $v['leave_type_id'])->where('tenant_default', true)->exists()) {
                throw ValidationException::withMessages(['tenant_default' => 'This leave type already has a tenant default. Revise it or use employee assignments.']);
            }
            $leaves = DB::table('employee_leaves')->where('leave_type_id', $v['leave_type_id'])->whereIn('state', ['pending', 'approved'])->whereDate('leave_date', '>=', $date);
            $slips = DB::table('salary_slips')->whereIn('status', ['reviewed', 'approved', 'paid'])->whereDate('period_end', '>=', $date);
            foreach ([$leaves, $slips] as $query) {
                $table = $query->from;
                $query->where(function ($q) use ($v, $policy, $table) {
                    $assignments = fn ($a) => $a->selectRaw('1')->from('employee_leave_policy_assignments as a')
                        ->join('leave_policies as p', 'p.id', '=', 'a.leave_policy_id')
                        ->whereColumn('a.user_id', $table.'.user_id')->where('p.leave_type_id', $v['leave_type_id'])
                        ->whereColumn('a.starts_on', '<=', $table.'.'.($table === 'employee_leaves' ? 'leave_date' : 'period_end'))
                        ->where(fn ($w) => $w->whereNull('a.ends_on')->orWhereColumn('a.ends_on', '>=', $table.'.'.($table === 'employee_leaves' ? 'leave_date' : 'period_start')));
                    if ($v['tenant_default']) {
                        $q->whereNotExists($assignments);
                    } elseif ($policy) {
                        $q->whereExists(fn ($a) => $assignments($a)->where('a.leave_policy_id', $policy->id));
                    } else {
                        $q->whereRaw('1 = 0');
                    }
                });
            }
            if ($slips->exists() || $leaves->exists()) {
                throw ValidationException::withMessages(['effective_from' => 'Reconcile affected employee leave and closed payroll periods before this policy change.']);
            }
            $policy ??= LeavePolicy::create(['name' => $v['name'], 'leave_type_id' => $v['leave_type_id'], 'tenant_default' => $v['tenant_default']]);
            $policy->update(['name' => $v['name']]);
            $policy->revisions()->create(['effective_from' => $date, 'rules' => $v['rules'], 'reason' => $v['reason'], 'created_by_user_id' => $actor->id]);

            return $policy;
        });
    }

    public function resolve(User $user, int $typeId, Carbon $date): ?LeavePolicyRevision
    {
        $assignment = DB::table('employee_leave_policy_assignments')->join('leave_policies', 'leave_policies.id', '=', 'employee_leave_policy_assignments.leave_policy_id')
            ->where('user_id', $user->id)->where('leave_type_id', $typeId)->whereDate('starts_on', '<=', $date)
            ->where(fn ($q) => $q->whereNull('ends_on')->orWhereDate('ends_on', '>=', $date))->first();
        $policyId = $assignment?->leave_policy_id ?? LeavePolicy::where('leave_type_id', $typeId)->where('tenant_default', true)->value('id');

        return $policyId ? LeavePolicyRevision::where('leave_policy_id', $policyId)->whereDate('effective_from', '<=', $date)->orderByDesc('effective_from')->first() : null;
    }

    public function assign(LeavePolicy $policy, array $userIds, Carbon $start, ?Carbon $end, User $actor): void
    {
        abort_unless((int) $actor->privilege === 14, 403);
        if ($end && $end->lt($start)) {
            throw ValidationException::withMessages(['ends_on' => 'End must follow start.']);
        }
        DB::transaction(function () use ($policy, $userIds, $start, $end, $actor) {
            $revision = $policy->revisions()->whereDate('effective_from', '<=', $start)->orderByDesc('effective_from')->first()
                ?? $policy->revisions()->orderByDesc('effective_from')->firstOrFail();
            $periodFrom = $revision->rules['leave_period_from'] ?? null;
            $periodTo = $revision->rules['leave_period_to'] ?? null;
            if (! ($revision->rules['mode'] === 'annual' && $periodFrom) && ($start->day !== 1 || ($end && ! $end->isLastOfMonth()))) {
                throw ValidationException::withMessages(['starts_on' => 'Employee policy assignments start on a month boundary and end at month end. Employment dates still prorate join/exit.']);
            }
            if ($revision->rules['mode'] === 'annual' && $periodTo && ! $end) {
                $end = Carbon::parse($periodTo);
            }
            $wrongAnnualStart = $periodFrom ? $start->toDateString() !== $periodFrom : $start->format('m-d') !== '01-01';
            $wrongAnnualEnd = $end && ($periodTo ? $end->toDateString() !== $periodTo : $end->format('m-d') !== '12-31');
            if ($revision->rules['mode'] === 'annual' && ($wrongAnnualStart || $wrongAnnualEnd)) {
                throw ValidationException::withMessages(['starts_on' => 'Annual assignments follow the configured leave period, or 1 January through 31 December when no period is set.']);
            }
            foreach (array_unique($userIds) as $id) {
                $user = User::employees()->lockForUpdate()->findOrFail($id);
                $overlap = DB::table('employee_leave_policy_assignments')->where('user_id', $id)->whereIn('leave_policy_id', LeavePolicy::where('leave_type_id', $policy->leave_type_id)->pluck('id'))
                    ->when($end, fn ($q) => $q->whereDate('starts_on', '<=', $end))->where(fn ($q) => $q->whereNull('ends_on')->orWhereDate('ends_on', '>=', $start))->exists();
                if ($overlap) {
                    throw ValidationException::withMessages(['users' => 'An employee already has an overlapping assignment for this leave type.']);
                }
                if (DB::table('salary_slips')->where('user_id', $id)->whereIn('status', ['reviewed', 'approved', 'paid'])
                    ->whereDate('period_end', '>=', $start)->when($end, fn ($q) => $q->whereDate('period_start', '<=', $end))->exists()) {
                    throw ValidationException::withMessages(['starts_on' => 'Reconcile the selected employee’s closed payroll before changing this assignment.']);
                }
                if (DB::table('leave_credit_lots')->where('user_id', $id)->where('leave_type_id', $policy->leave_type_id)->whereDate('available_on', '>=', $start)->exists()
                    || DB::table('employee_leaves')->where('user_id', $id)->where('leave_type_id', $policy->leave_type_id)->whereIn('state', ['pending', 'approved'])->whereDate('leave_date', '>=', $start)->exists()) {
                    throw ValidationException::withMessages(['starts_on' => 'Assignment cannot replace already granted credits or approved leave. Use a later cutover.']);
                }
                DB::table('employee_leave_policy_assignments')->insert(['user_id' => $id, 'leave_policy_id' => $policy->id, 'starts_on' => $start->toDateString(), 'ends_on' => $end?->toDateString(), 'created_by_user_id' => $actor->id, 'created_at' => now(), 'updated_at' => now()]);
            }
        });
    }

    public function endAssignment(int $assignmentId, Carbon $end, string $reason, User $actor): void
    {
        abort_unless((int) $actor->privilege === 14, 403);
        if ($end->lt(Carbon::today(LeaveSettingsService::timezone())) || trim($reason) === '') {
            throw ValidationException::withMessages(['ends_on' => 'Choose a future month end and record the reason for changing assignment.']);
        }
        DB::transaction(function () use ($assignmentId, $end, $reason, $actor) {
            $assignment = DB::table('employee_leave_policy_assignments')->where('id', $assignmentId)->firstOrFail();
            User::lockForUpdate()->findOrFail($assignment->user_id);
            $assignment = DB::table('employee_leave_policy_assignments')->where('id', $assignmentId)->lockForUpdate()->first();
            $policy = LeavePolicy::findOrFail($assignment->leave_policy_id);
            $revision = $policy->revisions()->whereDate('effective_from', '<=', $end)->orderByDesc('effective_from')->first()
                ?? $policy->revisions()->orderByDesc('effective_from')->firstOrFail();
            if (($revision->rules['mode'] !== 'annual' && ! $end->isLastOfMonth()) || $end->lt(Carbon::parse($assignment->starts_on)) || ($assignment->ends_on && $end->gt(Carbon::parse($assignment->ends_on))) || ($revision->rules['mode'] === 'annual' && (! empty($revision->rules['leave_period_to']) ? $end->toDateString() !== $revision->rules['leave_period_to'] : $end->format('m-d') !== '12-31'))) {
                throw ValidationException::withMessages(['ends_on' => 'End cannot precede start or extend the original window; annual assignments end on the configured leave-period end (31 December when no period is set).']);
            }
            if (DB::table('employee_leaves')->where('user_id', $assignment->user_id)->where('leave_type_id', $policy->leave_type_id)->whereIn('state', ['pending', 'approved'])->whereDate('leave_date', '>', $end)->exists()
                || DB::table('leave_credit_lots')->where('user_id', $assignment->user_id)->whereIn('leave_policy_revision_id', $policy->revisions()->pluck('id'))->whereDate('available_on', '>', $end)->exists()) {
                throw ValidationException::withMessages(['ends_on' => 'Reconcile future leave or issued credits before shortening assignment.']);
            }
            $changes = json_decode($assignment->changes ?? '[]', true, 512, JSON_THROW_ON_ERROR);
            $changes[] = ['previous_end' => $assignment->ends_on, 'new_end' => $end->toDateString(), 'reason' => $reason, 'actor_id' => $actor->id, 'recorded_at' => now()->toIso8601String()];
            DB::table('employee_leave_policy_assignments')->where('id', $assignmentId)->update(['ends_on' => $end->toDateString(), 'changes' => json_encode($changes, JSON_THROW_ON_ERROR), 'updated_at' => now()]);
        }, 3);
    }

    public function installDefaults(Carbon $effective, User $actor): void
    {
        DB::transaction(function () use ($effective, $actor) {
            foreach ([['Monthly paid leave', 'MONTHLY', 'monthly'], ['Compensatory leave', 'COMP', 'compensatory']] as [$name, $code, $mode]) {
                $type = LeaveType::firstOrCreate(['code' => $code], ['name' => $name, 'active' => true, 'is_paid' => true, 'annual_allowance_half_units' => 0]);
                if (LeavePolicy::where('leave_type_id', $type->id)->where('tenant_default', true)->exists()) {
                    continue;
                }
                $rules = self::defaults();
                $rules['mode'] = $mode;
                $rules['comp_enabled'] = $mode === 'compensatory';
                $rules['grant_half_units'] = $mode === 'compensatory' ? 0 : 4;
                $this->save(null, ['name' => $name.' — editable starter', 'leave_type_id' => $type->id, 'tenant_default' => true, 'effective_from' => $effective->toDateString(), 'reason' => 'Tenant administrator installed editable starter policies; not a statutory entitlement.', 'rules' => $rules], $actor);
            }
        });
    }
}
