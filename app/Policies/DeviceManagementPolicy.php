<?php

namespace App\Policies;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;

final class DeviceManagementPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->canManageDevices();
    }

    public function view(User $user, Model $record): bool
    {
        return $user->canManageDevices();
    }

    public function create(User $user): bool
    {
        return $user->canManageDevices();
    }

    public function update(User $user, Model $record): bool
    {
        return $user->canManageDevices();
    }

    public function delete(User $user, Model $record): bool
    {
        return $user->canManageDevices();
    }

    public function deleteAny(User $user): bool
    {
        return $user->canManageDevices();
    }
}
