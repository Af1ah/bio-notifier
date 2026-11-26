<?php

namespace App\Services\EmployeePortal;

use App\Models\Department;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;

final class DepartmentReview
{
    public static function canAssign(): bool
    {
        if (! tenancy()->initialized) {
            return false;
        }

        $admin = auth('web')->user();

        return (bool) ($admin?->is_enabled && $admin->canManageDevices());
    }

    public static function hasScope(User $actor): bool
    {
        return self::canUseEssReview($actor) && Department::where('manager_id', $actor->id)->exists();
    }

    public static function employees(User $actor): Builder
    {
        $query = User::query()->employees()->whereKeyNot($actor->id);

        return self::canUseEssReview($actor)
            ? $query->whereIn('department_id', Department::where('manager_id', $actor->id)->select('id'))
            : $query->whereRaw('1 = 0');
    }

    /** Called while the employee's current department is locked against reassignment. */
    public static function authorizeDecision(User $actor, User $employee): void
    {
        abort_unless($actor->is_enabled && $actor->id !== $employee->id, 403);
        if ($actor->hasManagementAccess()) {
            return;
        }

        abort_unless(self::canUseEssReview($actor) && $employee->department_id
            && Department::whereKey($employee->department_id)->where('manager_id', $actor->id)->sharedLock()->first(['id']), 403);
    }

    private static function canUseEssReview(User $actor): bool
    {
        return tenancy()->initialized && $actor->canAccessEmployeePortal() && ! $actor->requires_password_change;
    }
}
