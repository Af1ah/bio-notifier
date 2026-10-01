<?php

namespace App\Policies;

use App\Models\EmployeeLeave;
use App\Models\User;

class EmployeeLeavePolicy
{
    private function admin(User $user): bool
    {
        return (int) $user->privilege === 14;
    }

    public function viewAny(User $user): bool { return $this->admin($user); }
    public function view(User $user, EmployeeLeave $leave): bool { return $this->admin($user); }
    public function create(User $user): bool { return $this->admin($user); }
    public function update(User $user, EmployeeLeave $leave): bool { return $this->admin($user); }
    public function delete(User $user, EmployeeLeave $leave): bool { return $this->admin($user); }
}
