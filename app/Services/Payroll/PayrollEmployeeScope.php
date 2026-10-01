<?php

namespace App\Services\Payroll;

use Illuminate\Database\Eloquent\Builder;

class PayrollEmployeeScope
{
    public static function apply(Builder $query, array $selection): Builder
    {
        $query->employees();
        foreach (['branch_ids' => 'branch_id', 'department_ids' => 'department_id', 'user_ids' => 'id'] as $key => $column) {
            if (! empty($selection[$key])) {
                $query->whereIn($query->getModel()->qualifyColumn($column), $selection[$key]);
            }
        }
        if (! empty($selection['task_group_ids'])) {
            $query->whereHas('taskGroups', fn (Builder $groups) => $groups->whereIn('task_groups.id', $selection['task_group_ids']));
        }

        return $query;
    }
}
