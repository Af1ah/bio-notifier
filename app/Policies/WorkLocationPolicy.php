<?php

namespace App\Policies;

use App\Models\User;
use App\Models\WorkLocation;

class WorkLocationPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->hasManagementAccess();
    }

    public function view(User $user, WorkLocation $location): bool
    {
        return $user->hasManagementAccess();
    }

    public function create(User $user): bool
    {
        return $user->hasManagementAccess();
    }

    public function update(User $user, WorkLocation $location): bool
    {
        return $user->hasManagementAccess();
    }

    public function delete(User $user, WorkLocation $location): bool
    {
        return false;
    }

    public function deleteAny(User $user): bool
    {
        return false;
    }
}
