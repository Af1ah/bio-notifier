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
            'prorate_join_exit' => true, 'carry_enabled' => true, 'carry_roll_again' => true, 'carry_cap_half_units' => 12,
            'balance_cap_half_units' => 24, 'expiry_months' => 3, 'fixed_expiry_month' => null, 'fixed_expiry_day' => null,
            'allow_unpaid_excess' => false, 'comp_enabled' => false, 'comp_holiday' => true, 'comp_off' => true,
            'comp_half_minutes' => 240, 'comp_full_minutes' => 480, 'comp_expiry_days' => 90,
            'comp_same_day' => false, 'comp_allow_overtime' => false, 'comp_reward' => 'choice', 'comp_payment_currency' => 'INR', 'comp_payment_full_minor' => 0];
    }

    public function save(?LeavePolicy $policy, array $data, User $actor): LeavePolicy
    {
        abort_unless((int) $actor->privilege === 14, 403);
        $v = Validator::make($data, [
            'name' => 'required|string|max:120', 'leave_type_id' => 'required|exists:leave_types,id',
            'tenant_default' => 'required|boolean', 'effective_from' => 'required|date', 'reason' => 'required|string|max:1000',
            'rules.enabled' => 'required|boolean', 'rules.mode' => 'required|in:monthly,annual,compensatory',
            'rules.grant_half_units' => 'required|integer|min:0|max:732', 'rules.grant_timing' => 'required|in:start,end',
            'rules.prorate_join_exit' => 'required|boolean', 'rules.carry_enabled' => 'required|boolean',
            'rules.carry_roll_again' => 'required|boolean',
            'rules.carry_cap_half_units' => 'required|integer|min:0|max:732', 'rules.balance_cap_half_units' => 'required|integer|min:0|max:1464',
            'rules.expiry_months' => 'required|integer|min:0|max:24',
            'rules.fixed_expiry_month' => 'nullable|integer|min:1|max:12', 'rules.fixed_expiry_day' => 'nullable|integer|min:1|max:31',
            'rules.allow_unpaid_excess' => 'required|boolean', 'rules.comp_enabled' => 'required|boolean',
            'rules.comp_holiday' => 'required|boolean', 'rules.comp_off' => 'required|boolean',
            'rules.comp_half_minutes' => 'required|integer|min:1|max:1440', 'rules.comp_full_minutes' => 'required|integer|gte:rules.comp_half_minutes|max:1440',
            'rules.comp_expiry_days' => 'required|integer|min:0|max:730', 'rules.comp_same_day' => 'required|boolean',
            'rules.comp_allow_overtime' => 'required|boolean',
            'rules.comp_payment_currency' => 'required|string|size:3|regex:/^[A-Z]{3}$/',
            'rules.comp_reward' => 'required|in:choice,leave,payment,both', 'rules.comp_payment_full_minor' => 'required|integer|min:0|max:100000000',
        ])->validate();
        if (empty($v['rules']['fixed_expiry_month']) !== empty($v['rules']['fixed_expiry_day'])) {
            throw ValidationException::withMessages(['rules.fixed_expiry_day' => 'Set both cutoff month and day, or neither.']);
        }

        if (! LeaveType::findOrFail($v['leave_type_id'])->is_paid) {
            throw ValidationException::withMessages(['leave_type_id' => 'Balance policies require a paid leave type. Unpaid leave remains separate.']);
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
                if ($latest && ($latest->rules['mode'] === 'annual' ? $date->format('m-d') !== '01-01' : ($latest->rules['mode'] === 'monthly' && $date->day !== 1))) {
                    throw ValidationException::withMessages(['effective_from' => 'After initial enrolment, entitlement revisions begin at a period boundary (month start, or 1 January for annual policies).']);
                }
                $lastGrant = DB::table('leave_credit_lots')->whereIn('leave_policy_revision_id', $policy->revisions()->pluck('id'))->max('available_on');
                if ($lastGrant && $date->lte(Carbon::parse($lastGrant))) {
                    throw ValidationException::withMessages(['effective_from' => 'Existing credits are immutable. Start the revision after already issued credits.']);
                }
            } elseif ($v['tenant_default'] && LeavePolicy::where('leave_type_id', $v['leave_type_id'])->where('tenant_default', true)->exists()) {
                throw ValidationException::withMessages(['tenant_default' => 'This leave type already has a tenant default. Revise it or use employee assignments.']);
            }
            if (DB::table('salary_slips')->whereIn('status', ['reviewed', 'approved', 'paid'])->whereDate('period_end', '>=', $date)->exists() || DB::table('employee_leaves')->whereIn('state', ['pending', 'approved'])->whereDate('leave_date', '>=', $date)->exists()) {
                throw ValidationException::withMessages(['effective_from' => 'Start after existing approved leave and closed payroll periods, or reconcile them before enrolment.']);
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
        if ($start->day !== 1 || ($end && ! $end->isLastOfMonth())) {
            throw ValidationException::withMessages(['starts_on' => 'Employee policy assignments start on a month boundary and end at month end. Employment dates still prorate join/exit.']);
        }
        if ($end && $end->lt($start)) {
            throw ValidationException::withMessages(['ends_on' => 'End must follow start.']);
        }
        DB::transaction(function () use ($policy, $userIds, $start, $end, $actor) {
            $revision = $policy->revisions()->orderByDesc('effective_from')->firstOrFail();
            if ($revision->rules['mode'] === 'annual' && ($start->format('m-d') !== '01-01' || ($end && $end->format('m-d') !== '12-31'))) {
                throw ValidationException::withMessages(['starts_on' => 'Annual policy assignments follow the calendar leave year: 1 January through 31 December.']);
            }
            foreach (array_unique($userIds) as $id) {
                $user = User::employees()->lockForUpdate()->findOrFail($id);
                $overlap = DB::table('employee_leave_policy_assignments')->where('user_id', $id)->whereIn('leave_policy_id', LeavePolicy::where('leave_type_id', $policy->leave_type_id)->pluck('id'))
                    ->when($end, fn ($q) => $q->whereDate('starts_on', '<=', $end))->where(fn ($q) => $q->whereNull('ends_on')->orWhereDate('ends_on', '>=', $start))->exists();
                if ($overlap) {
                    throw ValidationException::withMessages(['users' => 'An employee already has an overlapping assignment for this leave type.']);
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
        if (! $end->isLastOfMonth() || $end->lt(Carbon::today(LeaveSettingsService::timezone())) || trim($reason) === '') {
            throw ValidationException::withMessages(['ends_on' => 'Choose a future month end and record the reason for changing assignment.']);
        }
        DB::transaction(function () use ($assignmentId, $end, $reason, $actor) {
            $assignment = DB::table('employee_leave_policy_assignments')->where('id', $assignmentId)->firstOrFail();
            User::lockForUpdate()->findOrFail($assignment->user_id);
            $assignment = DB::table('employee_leave_policy_assignments')->where('id', $assignmentId)->lockForUpdate()->first();
            $policy = LeavePolicy::findOrFail($assignment->leave_policy_id);
            $revision = $policy->revisions()->orderByDesc('effective_from')->firstOrFail();
            if ($end->lt(Carbon::parse($assignment->starts_on)) || ($assignment->ends_on && $end->gt(Carbon::parse($assignment->ends_on))) || ($revision->rules['mode'] === 'annual' && $end->format('m-d') !== '12-31')) {
                throw ValidationException::withMessages(['ends_on' => 'End cannot precede start or extend the original window; annual assignments end on 31 December.']);
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
                $type = LeaveType::firstOrCreate(['code' => $code], ['name' => $name, 'active' => true, 'is_paid' => true, 'annual_allowance_half_units' => 0, 'deduction_method' => 'salary_day_rate']);
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
