<?php

namespace App\Policies;

use App\Models\SalarySlip;
use App\Models\User;

class SalarySlipPolicy
{
    private function admin(User $user): bool
    {
        return (int) $user->privilege === 14;
    }

    public function viewAny(User $user): bool { return $this->admin($user); }
    public function view(User $user, SalarySlip $salarySlip): bool { return $this->admin($user); }
    public function create(User $user): bool { return false; }
    public function update(User $user, SalarySlip $salarySlip): bool { return false; }
    public function delete(User $user, SalarySlip $salarySlip): bool { return false; }
}
