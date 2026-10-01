<?php

namespace App\Policies;

use App\Models\AttendanceApproval;
use App\Models\User;

class AttendanceApprovalPolicy
{
    private function admin(User $u): bool
    {
        return (int) $u->privilege === 14;
    }

    public function viewAny(User $u): bool
    {
        return $this->admin($u);
    }

    public function view(User $u, AttendanceApproval $m): bool
    {
        return $this->admin($u);
    }

    public function update(User $u, AttendanceApproval $m): bool
    {
        return $this->admin($u);
    }

    public function create(User $u): bool
    {
        return false;
    }

    public function delete(User $u, AttendanceApproval $m): bool
    {
        return false;
    }
}
