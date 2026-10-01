<?php

namespace App\Services\Attendance;

use App\Models\ShiftAssignmentSet;
use App\Models\User;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;

class ShiftAssignmentResolver
{
    public function resolve(User $user, CarbonInterface $workDate): ?ShiftAssignmentSet
    {
        $date = $workDate->copy()->endOfDay();
        $sources = [
            ['user_shift_assignments', 'user_id', [$user->id]],
            ['task_group_shift_assignments', 'task_group_id', $user->taskGroups()->pluck('task_groups.id')->all()],
            ['department_shift_assignments', 'department_id', array_filter([$user->department_id])],
            ['branch_shift_assignments', 'branch_id', array_filter([$user->branch_id])],
        ];

        foreach ($sources as [$table, $ownerColumn, $owners]) {
            if ($owners === []) {
                continue;
            }
            $id = DB::table($table)->join('shift_assignment_sets', 'shift_assignment_sets.id', '=', "$table.shift_assignment_set_id")
                ->whereIn("$table.$ownerColumn", $owners)->where('shift_assignment_sets.active', true)
                ->where('shift_assignment_sets.effective_from', '<=', $date)
                ->where(fn ($query) => $query->whereNull('shift_assignment_sets.effective_to')->orWhereDate('shift_assignment_sets.effective_to', '>=', $workDate->toDateString()))
                ->orderByDesc('shift_assignment_sets.effective_from')->value('shift_assignment_sets.id');
            if ($id) {
                return $this->load($id);
            }
        }

        $id = DB::table('default_shift_assignments')->join('shift_assignment_sets', 'shift_assignment_sets.id', '=', 'default_shift_assignments.shift_assignment_set_id')
            ->where('default_shift_assignments.active', true)->where('default_shift_assignments.effective_from', '<=', $date)
            ->where(fn ($query) => $query->whereNull('default_shift_assignments.effective_to')->orWhereDate('default_shift_assignments.effective_to', '>=', $workDate->toDateString()))
            ->where('shift_assignment_sets.active', true)->orderByDesc('default_shift_assignments.effective_from')->value('shift_assignment_sets.id');

        return $id ? $this->load($id) : null;
    }

    private function load(int $id): ShiftAssignmentSet
    {
        return ShiftAssignmentSet::with(['slots.schedule', 'slots.ruleRevision', 'dailyPolicyRuleRevision'])->findOrFail($id);
    }
}
