<?php

namespace App\Services\Leave;

use App\Models\EmployeeLeave;
use App\Models\LeavePolicy;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class LeavePolicyRemovalService
{
    public function preview(LeavePolicy $policy, User $actor): array
    {
        abort_unless((int) $actor->privilege === 14, 403);
        $plan = $this->plan($policy);

        return ['signature' => $plan['signature'], 'summary' => implode("\n", [
            'Policy: '.$policy->name,
            'Assignments removed: '.count($plan['snapshot']['assignments']),
            'Leave records removed: '.count($plan['delete_leaves']),
            'Credits removed: '.count($plan['delete_lots']),
            'Work rewards removed: '.count($plan['delete_awards']),
            'Draft payslips removed: '.count($plan['delete_slips']),
            'Processed payslips preserved: '.count($plan['protected_slips']),
            'Historical leave records retained: '.count($plan['keep_leaves']),
            'Retained historical credits will not be available for new leave.',
            'The leave category and raw attendance records remain unchanged.',
        ])];
    }

    public function remove(LeavePolicy $policy, User $actor, string $name, string $reason, string $signature): void
    {
        abort_unless((int) $actor->privilege === 14, 403);
        if ($name !== $policy->name || trim($reason) === '' || mb_strlen($reason) > 1000) {
            throw ValidationException::withMessages(['confirmation' => 'Enter the exact policy name and a removal reason.']);
        }
        DB::transaction(function () use ($policy, $actor, $name, $reason, $signature) {
            // Match policy-write lock order, then serialize payroll, grants and leave writes.
            User::where('privilege', 14)->orderBy('id')->lockForUpdate()->get();
            $before = $this->plan($policy);
            User::whereIn('id', $before['user_ids'])->orderBy('id')->lockForUpdate()->get();
            $policy = LeavePolicy::lockForUpdate()->findOrFail($policy->id);
            $plan = $this->plan($policy);
            if ($policy->name !== $name || ! hash_equals($plan['signature'], $signature)) {
                throw ValidationException::withMessages(['confirmation' => 'Policy, leave or payroll changed during review. Reopen Remove policy and review the updated totals.']);
            }
            DB::table('leave_policy_removals')->insert([
                'original_policy_id' => $policy->id, 'policy_name' => $policy->name,
                'removed_by_user_id' => $actor->id, 'reason' => trim($reason),
                'snapshot' => json_encode($plan['snapshot'] + ['protected_payslip_ids' => $plan['protected_slips'], 'retained_leave_ids' => $plan['keep_leaves']], JSON_THROW_ON_ERROR),
                'removed_at' => now(),
            ]);
            foreach (EmployeeLeave::whereIn('id', $plan['delete_leaves'])->get() as $leave) {
                // Restore any allocations belonging to another policy before removing the request.
                app(LeaveBalanceService::class)->release($leave, $actor);
            }
            DB::table('leave_allocations')->whereIn('employee_leave_id', $plan['delete_leaves'])->delete();
            DB::table('employee_leaves')->whereIn('id', $plan['delete_leaves'])->delete();
            DB::table('compensatory_awards')->whereIn('id', $plan['delete_awards'])->delete();
            DB::table('leave_ledger_entries')->whereIn('leave_credit_lot_id', $plan['delete_lots'])->delete();
            DB::table('leave_allocations')->whereIn('leave_credit_lot_id', $plan['delete_lots'])->delete();
            DB::table('leave_credit_lots')->whereIn('id', $plan['delete_lots'])->delete();
            DB::table('employee_leave_policy_assignments')->where('leave_policy_id', $policy->id)->delete();
            // Detach the minimum revision evidence still required by processed payroll.
            DB::table('leave_policy_revisions')->where('leave_policy_id', $policy->id)->whereIn('id', $plan['keep_revisions'])->update(['leave_policy_id' => null]);
            DB::table('leave_policy_revisions')->where('leave_policy_id', $policy->id)->delete();
            DB::table('salary_slips')->whereIn('id', $plan['delete_slips'])->where('status', 'draft')->delete();
            DB::table('leave_policies')->where('id', $policy->id)->delete();
        }, 3);
    }

    private function rows(string $table, callable $scope): array
    {
        return $scope(DB::table($table))->orderBy('id')->get()->map(fn ($row) => (array) $row)->all();
    }

    private function plan(LeavePolicy $policy): array
    {
        $revisions = $this->rows('leave_policy_revisions', fn ($q) => $q->where('leave_policy_id', $policy->id));
        $revisionIds = array_column($revisions, 'id');
        $first = collect($revisions)->min('effective_from');
        $assignments = $this->rows('employee_leave_policy_assignments', fn ($q) => $q->where('leave_policy_id', $policy->id));
        $lots = $this->rows('leave_credit_lots', fn ($q) => $q->whereIn('leave_policy_revision_id', $revisionIds));
        $lotIds = array_column($lots, 'id');
        $awards = $this->rows('compensatory_awards', fn ($q) => $q->whereIn('leave_policy_revision_id', $revisionIds));
        $otherAssignments = DB::table('employee_leave_policy_assignments as a')->join('leave_policies as p', 'p.id', '=', 'a.leave_policy_id')
            ->where('p.leave_type_id', $policy->leave_type_id)->orderBy('a.id')->select('a.*')->get();
        $userIds = collect(array_merge(array_column($assignments, 'user_id'), array_column($lots, 'user_id'), array_column($awards, 'user_id')));
        if ($policy->tenant_default) {
            $userIds = $userIds->merge(User::employees()->pluck('id'));
        }
        $userIds = $userIds->unique()->sort()->values()->all();
        $leaves = $this->rows('employee_leaves', fn ($q) => $q->whereIn('user_id', $userIds)->where('leave_type_id', $policy->leave_type_id));
        $linkedLeaveIds = DB::table('leave_allocations')->whereIn('leave_credit_lot_id', $lotIds)->pluck('employee_leave_id')->all();
        $leaves = array_values(array_filter($leaves, function ($leave) use ($policy, $first, $otherAssignments, $linkedLeaveIds) {
            if (in_array($leave['id'], $linkedLeaveIds)) {
                return true;
            }
            if (! $first || $leave['leave_date'] < $first) {
                return false;
            }
            foreach ($otherAssignments as $assignment) {
                if ($assignment->user_id == $leave['user_id'] && $assignment->starts_on <= $leave['leave_date'] && (! $assignment->ends_on || $assignment->ends_on >= $leave['leave_date'])) {
                    return $assignment->leave_policy_id == $policy->id;
                }
            }

            return (bool) $policy->tenant_default;
        }));
        $leaveIds = array_column($leaves, 'id');
        $allocations = $this->rows('leave_allocations', fn ($q) => $q->where(fn ($q) => $q->whereIn('employee_leave_id', $leaveIds)->orWhereIn('leave_credit_lot_id', $lotIds)));
        $slips = $this->rows('salary_slips', fn ($q) => $q->whereIn('user_id', $userIds));
        $processed = array_values(array_filter($slips, fn ($s) => in_array($s['status'], ['reviewed', 'approved', 'paid'])));
        $protected = function ($userId, $date, bool $history = false) use ($processed) {
            foreach ($processed as $slip) {
                $start = $history ? substr($slip['period_start'], 0, 4).'-01-01' : $slip['period_start'];
                if ($slip['user_id'] == $userId && $date >= $start && $date <= $slip['period_end']) {
                    return true;
                }
            }

            return false;
        };
        $keepLeaves = array_column(array_filter($leaves, fn ($l) => $protected($l['user_id'], $l['leave_date'], $l['state'] === 'approved')), 'id');
        $keepLots = array_column(array_filter($allocations, fn ($a) => in_array($a['employee_leave_id'], $keepLeaves) && in_array($a['leave_credit_lot_id'], $lotIds)), 'leave_credit_lot_id');
        $keepAwards = [];
        foreach ($awards as $award) {
            if ($protected($award['user_id'], $award['work_date']) || in_array($award['leave_credit_lot_id'], $keepLots)) {
                $keepAwards[] = $award['id'];
                if ($award['leave_credit_lot_id']) {
                    $keepLots[] = $award['leave_credit_lot_id'];
                }
            }
        }
        $keepRevisions = array_merge(
            array_column(array_filter($lots, fn ($l) => in_array($l['id'], $keepLots)), 'leave_policy_revision_id'),
            array_column(array_filter($awards, fn ($a) => in_array($a['id'], $keepAwards)), 'leave_policy_revision_id'),
        );
        $drafts = array_values(array_filter($slips, function ($slip) use ($leaves, $awards) {
            if ($slip['status'] !== 'draft') {
                return false;
            }
            foreach ($leaves as $leave) {
                $start = $leave['state'] === 'approved' ? substr($slip['period_start'], 0, 4).'-01-01' : $slip['period_start'];
                if ($leave['user_id'] == $slip['user_id'] && $leave['leave_date'] >= $start && $leave['leave_date'] <= $slip['period_end']) {
                    return true;
                }
            }
            foreach ($awards as $award) {
                if ($award['user_id'] == $slip['user_id'] && $award['work_date'] >= $slip['period_start'] && $award['work_date'] <= $slip['period_end']) {
                    return true;
                }
            }

            return false;
        }));
        $snapshot = ['policy' => (array) DB::table('leave_policies')->where('id', $policy->id)->first(), 'revisions' => $revisions, 'assignments' => $assignments, 'lots' => $lots,
            'entries' => $this->rows('leave_ledger_entries', fn ($q) => $q->whereIn('leave_credit_lot_id', $lotIds)),
            'allocations' => $allocations, 'leaves' => $leaves, 'awards' => $awards,
            'draft_payslips' => $drafts];

        return ['snapshot' => $snapshot, 'user_ids' => $userIds,
            'signature' => hash('sha256', json_encode([$snapshot, $slips, $otherAssignments, $userIds], JSON_THROW_ON_ERROR)),
            'protected_slips' => array_column($processed, 'id'), 'delete_slips' => array_column($snapshot['draft_payslips'], 'id'),
            'keep_leaves' => $keepLeaves, 'keep_revisions' => array_values(array_unique($keepRevisions)),
            'delete_leaves' => array_values(array_diff($leaveIds, $keepLeaves)),
            'delete_lots' => array_values(array_diff($lotIds, $keepLots)),
            'delete_awards' => array_values(array_diff(array_column($awards, 'id'), $keepAwards))];
    }
}
