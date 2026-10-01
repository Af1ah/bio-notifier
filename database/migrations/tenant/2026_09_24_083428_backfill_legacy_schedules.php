<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        $dayMap = ['sunday' => 0, 'monday' => 1, 'tuesday' => 2, 'wednesday' => 3, 'thursday' => 4, 'friday' => 5, 'saturday' => 6];
        $defaultCreated = DB::table('default_shift_assignments')->exists();
        foreach (DB::table('schedules')->orderBy('id')->get() as $schedule) {
            if (DB::table('shift_rule_revisions')->where('schedule_id', $schedule->id)->exists()) {
                continue;
            }
            $legacy = json_decode($schedule->rules ?: '{}', true) ?: [];
            $working = collect($legacy['weekly']['days'] ?? [])->filter(fn ($day) => $day['is_working'] ?? false);
            $first = $working->first() ?: [];
            $rules = isset($legacy['start_time']) ? $legacy : [
                'start_time' => $first['start'] ?? '09:30', 'end_time' => $first['end'] ?? '18:30',
                'end_day_offset' => ($first['end'] ?? '18:30') <= ($first['start'] ?? '09:30'),
                'arrival_grace_minutes' => 15, 'departure_grace_minutes' => 15, 'earliest_arrival_minutes' => 60,
                'half_day_minutes' => 120, 'full_day_minutes' => 480, 'punch_method' => 'first_last',
                'overtime_basis' => 'after_required_hours', 'overtime_minimum_minutes' => 30,
                'auto_checkout_enabled' => false, 'checkout_cutoff_minutes' => 180,
                'weekdays' => collect($legacy['weekly']['days'] ?? [])->filter(fn ($day) => $day['is_working'] ?? false)->keys()->map(fn ($day) => $dayMap[$day])->values()->all() ?: [1, 2, 3, 4, 5, 6],
                'breaks' => collect($legacy['weekly']['breaks'] ?? [])->map(function ($break) use ($legacy, $dayMap, $first) {
                    $weekdays = collect($legacy['weekly']['days'] ?? [])->filter(fn ($day) => ($day['breaks'][$break['id']]['is_active'] ?? false))->keys()->map(fn ($day) => $dayMap[$day])->values()->all();

                    return ['name' => $break['name'], 'start_time' => $break['start'], 'duration_minutes' => ($break['duration_unit'] ?? 'minutes') === 'hours' ? $break['duration'] * 60 : $break['duration'], 'day_offset' => $break['start'] < ($first['start'] ?? '09:30') ? 1 : 0, 'weekdays' => $weekdays];
                })->values()->all(),
            ];
            $effectiveFrom = $schedule->valid_from ?: '2000-01-01';
            $revisionId = DB::table('shift_rule_revisions')->insertGetId(['schedule_id' => $schedule->id, 'revision' => 1, 'effective_from' => $effectiveFrom, 'effective_to' => $schedule->valid_to, 'start_time' => $rules['start_time'], 'end_time' => $rules['end_time'], 'end_day_offset' => (int) ($rules['end_day_offset'] ?? false), 'arrival_grace_minutes' => $rules['arrival_grace_minutes'] ?? 15, 'departure_grace_minutes' => $rules['departure_grace_minutes'] ?? 15, 'earliest_arrival_minutes' => $rules['earliest_arrival_minutes'] ?? 60, 'half_day_minutes' => $rules['half_day_minutes'] ?? 120, 'full_day_minutes' => $rules['full_day_minutes'] ?? 480, 'punch_method' => $rules['punch_method'] ?? 'first_last', 'overtime_basis' => $rules['overtime_basis'] ?? 'after_required_hours', 'overtime_minimum_minutes' => $rules['overtime_minimum_minutes'] ?? 30, 'auto_checkout_enabled' => $rules['auto_checkout_enabled'] ?? false, 'auto_checkout_time' => $rules['auto_checkout_time'] ?? null, 'auto_checkout_day_offset' => $rules['auto_checkout_day_offset'] ?? null, 'checkout_cutoff_minutes' => $rules['checkout_cutoff_minutes'] ?? 180, 'snapshot' => json_encode($rules), 'created_at' => now(), 'updated_at' => now()]);
            foreach ($rules['weekdays'] as $weekday) {
                DB::table('shift_rule_weekdays')->insert(['shift_rule_revision_id' => $revisionId, 'weekday' => $weekday]);
            }
            foreach ($rules['breaks'] ?? [] as $break) {
                $breakId = DB::table('shift_breaks')->insertGetId(['shift_rule_revision_id' => $revisionId, 'name' => $break['name'], 'start_time' => $break['start_time'], 'day_offset' => $break['day_offset'] ?? 0, 'duration_minutes' => $break['duration_minutes'], 'created_at' => now(), 'updated_at' => now()]);
                foreach ($break['weekdays'] as $weekday) {
                    DB::table('shift_break_weekdays')->insert(['shift_break_id' => $breakId, 'weekday' => $weekday]);
                }
            }
            DB::table('schedules')->where('id', $schedule->id)->update(['rules' => json_encode($rules)]);
            $ownerMap = ['App\\Models\\User' => ['user_shift_assignments', 'user_id'], 'App\\Models\\Branch' => ['branch_shift_assignments', 'branch_id'], 'App\\Models\\Department' => ['department_shift_assignments', 'department_id'], 'App\\Models\\TaskGroup' => ['task_group_shift_assignments', 'task_group_id']];
            if ($schedule->target_type && isset($ownerMap[$schedule->target_type])) {
                [$table, $column] = $ownerMap[$schedule->target_type];
                $setId = $this->set($schedule, $revisionId, $effectiveFrom);
                DB::table($table)->insert([$column => $schedule->target_id, 'shift_assignment_set_id' => $setId, 'created_at' => now(), 'updated_at' => now()]);
            } elseif (! $defaultCreated && in_array($schedule->name, ['General Shift', 'Regular Shift'], true)) {
                $setId = $this->set($schedule, $revisionId, $effectiveFrom);
                DB::table('default_shift_assignments')->insert(['shift_assignment_set_id' => $setId, 'effective_from' => $effectiveFrom, 'effective_to' => $schedule->valid_to, 'active' => true, 'created_at' => now(), 'updated_at' => now()]);
                $defaultCreated = true;
            }
        }
    }

    private function set(object $schedule, int $revisionId, string $effectiveFrom): int
    {
        $setId = DB::table('shift_assignment_sets')->insertGetId(['mode' => 'single', 'daily_policy_rule_revision_id' => $revisionId, 'effective_from' => $effectiveFrom, 'effective_to' => $schedule->valid_to, 'active' => true, 'created_at' => now(), 'updated_at' => now()]);
        DB::table('shift_assignment_slots')->insert(['shift_assignment_set_id' => $setId, 'schedule_id' => $schedule->id, 'shift_rule_revision_id' => $revisionId, 'position' => 1, 'punch_window_boundary_day_offset' => 0, 'created_at' => now(), 'updated_at' => now()]);

        return $setId;
    }

    public function down(): void {}
};
