<?php

namespace App\Policies;

use App\Models\Holiday;
use App\Models\User;

class HolidayPolicy
{
    private function admin(User $u): bool
    {
        return (int) $u->privilege === 14;
    }

    public function viewAny(User $u): bool
    {
        return $this->admin($u);
    }

    public function view(User $u, Holiday $m): bool
    {
        return $this->admin($u);
    }

    public function create(User $u): bool
    {
        return $this->admin($u);
    }

    public function update(User $u, Holiday $m): bool
    {
        return $this->admin($u);
    }

    public function delete(User $u, Holiday $m): bool
    {
        return $this->admin($u);
    }
}
