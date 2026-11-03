<?php

namespace App\Policies;

use App\Models\PortalPunch;
use App\Models\User;

class PortalPunchPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->hasManagementAccess();
    }

    public function view(User $user, PortalPunch $punch): bool
    {
        return $user->hasManagementAccess();
    }

    public function create(User $user): bool
    {
        return false;
    }

    public function update(User $user, PortalPunch $punch): bool
    {
        return false;
    }

    public function delete(User $user, PortalPunch $punch): bool
    {
        return false;
    }

    public function deleteAny(User $user): bool
    {
        return false;
    }
}
