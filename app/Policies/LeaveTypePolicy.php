<?php

namespace App\Policies;

use App\Models\LeaveType;
use App\Models\User;

class LeaveTypePolicy
{
    private function admin(User $user): bool
    {
        return (int) $user->privilege === 14;
    }

    public function viewAny(User $user): bool { return $this->admin($user); }
    public function view(User $user, LeaveType $leaveType): bool { return $this->admin($user); }
    public function create(User $user): bool { return $this->admin($user); }
    public function update(User $user, LeaveType $leaveType): bool { return $this->admin($user); }
    public function delete(User $user, LeaveType $leaveType): bool { return $this->admin($user); }
}
