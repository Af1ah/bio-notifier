<?php

namespace App\Policies;

use App\Models\AttendanceDay;
use App\Models\User;

class AttendanceDayPolicy
{
    private function admin(User $u): bool
    {
        return (int) $u->privilege === 14;
    }

    public function viewAny(User $u): bool
    {
        return $this->admin($u);
    }

    public function view(User $u, AttendanceDay $m): bool
    {
        return $this->admin($u);
    }

    public function update(User $u, AttendanceDay $m): bool
    {
        return $this->admin($u);
    }

    public function create(User $u): bool
    {
        return false;
    }

    public function delete(User $u, AttendanceDay $m): bool
    {
        return false;
    }
}
