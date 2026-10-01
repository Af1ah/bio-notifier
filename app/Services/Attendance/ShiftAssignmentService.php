<?php

namespace App\Services\Attendance;

use App\Models\Schedule;
use App\Models\ShiftAssignmentSet;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class ShiftAssignmentService
{
    public function setDefault(Schedule $schedule, string $effectiveFrom, ?string $effectiveTo = null): ShiftAssignmentSet
    {
        return DB::transaction(function () use ($schedule, $effectiveFrom, $effectiveTo) {
            $rule = $schedule->activeRuleRevision(Carbon::parse($effectiveFrom));
            if (! $rule) {
                throw ValidationException::withMessages(['effective_from' => 'The shift has no rule revision for this date.']);
            }
            $previousDay = Carbon::parse($effectiveFrom)->subDay()->toDateString();
            DB::table('default_shift_assignments')->where('active', true)->where('effective_from', '<=', $effectiveFrom)->where(fn ($q) => $q->whereNull('effective_to')->orWhere('effective_to', '>=', $effectiveFrom))->update(['effective_to' => $previousDay, 'updated_at' => now()]);
            $futureConflict = DB::table('default_shift_assignments')->where('active', true)->where('effective_from', '>', $effectiveFrom)->where('effective_from', '<=', $effectiveTo ?? '9999-12-31')->exists();
            if ($futureConflict) {
                throw ValidationException::withMessages(['effective_to' => 'A future default shift already overlaps this range.']);
            }
            $set = ShiftAssignmentSet::create(['mode' => 'single', 'daily_policy_rule_revision_id' => $rule?->id, 'effective_from' => $effectiveFrom, 'effective_to' => $effectiveTo, 'active' => true]);
            $set->slots()->create(['schedule_id' => $schedule->id, 'shift_rule_revision_id' => $rule?->id, 'position' => 1]);
            DB::table('default_shift_assignments')->insert(['shift_assignment_set_id' => $set->id, 'effective_from' => $effectiveFrom, 'effective_to' => $effectiveTo, 'active' => true, 'created_at' => now(), 'updated_at' => now()]);

            return $set;
        });
    }

    public function assign(string $ownerType, Model|Collection $owners, array $data): ShiftAssignmentSet
    {
        app(AttendanceRateLimiter::class)->ensure('assignment', 30);

        return DB::transaction(function () use ($ownerType, $owners, $data) {
            $scheduleIds = array_values($data['schedule_ids']);
            $effectiveDate = Carbon::parse($data['effective_from']);
            $rules = Schedule::whereIn('id', $scheduleIds)->get()->mapWithKeys(fn ($s) => [$s->id => $s->activeRuleRevision($effectiveDate)]);
            if ($rules->contains(null)) {
                throw ValidationException::withMessages(['schedule_ids' => 'Every selected shift needs an effective rule revision.']);
            }
            $map = ['user' => ['user_shift_assignments', 'user_id'], 'branch' => ['branch_shift_assignments', 'branch_id'], 'department' => ['department_shift_assignments', 'department_id'], 'task_group' => ['task_group_shift_assignments', 'task_group_id']];
            [$table, $column] = $map[$ownerType];
            $ownerList = $owners instanceof Collection ? $owners : collect([$owners]);
            foreach ($ownerList as $owner) {
                $conflict = DB::table($table)->join('shift_assignment_sets', 'shift_assignment_sets.id', '=', "$table.shift_assignment_set_id")->where("$table.$column", $owner->getKey())->where('shift_assignment_sets.active', true)->where('shift_assignment_sets.effective_from', '<=', $data['effective_to'] ?? '9999-12-31')->where(fn ($q) => $q->whereNull('shift_assignment_sets.effective_to')->orWhere('shift_assignment_sets.effective_to', '>=', $data['effective_from']))->exists();
                if ($conflict) {
                    throw ValidationException::withMessages(['effective_from' => 'This target already has an overlapping shift assignment.']);
                }
            }
            $created = null;
            foreach ($ownerList as $owner) {
                $set = ShiftAssignmentSet::create(['mode' => count($scheduleIds) > 1 ? 'multiple' : 'single', 'daily_policy_rule_revision_id' => $rules[$data['daily_policy_schedule_id'] ?? $scheduleIds[0]]?->id, 'effective_from' => $data['effective_from'], 'effective_to' => $data['effective_to'] ?? null, 'active' => true]);
                foreach ($scheduleIds as $position => $scheduleId) {
                    $set->slots()->create(['schedule_id' => $scheduleId, 'shift_rule_revision_id' => $rules[$scheduleId]?->id, 'position' => $position + 1]);
                }DB::table($table)->insert([$column => $owner->getKey(), 'shift_assignment_set_id' => $set->id, 'created_at' => now(), 'updated_at' => now()]);
                $created ??= $set;
            }

            return $created;
        });
    }

    public function end(string $ownerType, Model $owner, string $lastDay): void
    {
        $map = ['user' => ['user_shift_assignments', 'user_id'], 'branch' => ['branch_shift_assignments', 'branch_id'], 'department' => ['department_shift_assignments', 'department_id'], 'task_group' => ['task_group_shift_assignments', 'task_group_id']];
        [$table,$column] = $map[$ownerType];
        $id = DB::table($table)->join('shift_assignment_sets', 'shift_assignment_sets.id', '=', "$table.shift_assignment_set_id")->where("$table.$column", $owner->getKey())->where('shift_assignment_sets.active', true)->where('shift_assignment_sets.effective_from', '<=', $lastDay)->where(fn ($q) => $q->whereNull('shift_assignment_sets.effective_to')->orWhere('shift_assignment_sets.effective_to', '>=', $lastDay))->orderByDesc('shift_assignment_sets.effective_from')->value('shift_assignment_sets.id');
        if (! $id) {
            throw ValidationException::withMessages(['last_day' => 'No active assignment exists on this date.']);
        }ShiftAssignmentSet::whereKey($id)->update(['effective_to' => $lastDay]);
    }
}
