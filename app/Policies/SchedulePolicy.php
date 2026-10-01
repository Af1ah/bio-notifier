<?php

namespace App\Policies;

use App\Models\Schedule;
use App\Models\User;

class SchedulePolicy
{
    private function admin(User $u): bool
    {
        return (int) $u->privilege === 14;
    }

    public function viewAny(User $u): bool
    {
        return $this->admin($u);
    }

    public function view(User $u, Schedule $m): bool
    {
        return $this->admin($u);
    }

    public function create(User $u): bool
    {
        return $this->admin($u);
    }

    public function update(User $u, Schedule $m): bool
    {
        return $this->admin($u);
    }

    public function delete(User $u, Schedule $m): bool
    {
        return $this->admin($u);
    }
}
