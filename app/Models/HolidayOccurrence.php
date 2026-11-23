<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class HolidayOccurrence extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return ['holiday_date' => 'date'];
    }

    public function holiday()
    {
        return $this->belongsTo(Holiday::class);
    }

    public function scopeForEmployee($query, User $user)
    {
        $taskGroups = $user->taskGroups()->pluck('task_groups.id');

        return $query->whereHas('holiday', fn ($holiday) => $holiday->where('active', true)->where(fn ($coverage) => $coverage
            ->where(fn ($all) => $all->whereDoesntHave('branches')->whereDoesntHave('departments')->whereDoesntHave('taskGroups'))
            ->orWhereHas('branches', fn ($branch) => $branch->whereKey($user->branch_id))
            ->orWhereHas('departments', fn ($department) => $department->whereKey($user->department_id))
            ->orWhereHas('taskGroups', fn ($group) => $group->whereIn('task_groups.id', $taskGroups))));
    }
}
