<?php

namespace App\Models;

use Filament\Models\Contracts\FilamentUser;
use Filament\Panel;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Stancl\Tenancy\Database\Concerns\CentralConnection;

class Admin extends Authenticatable implements FilamentUser
{
    use CentralConnection;

    protected $table = 'users';

    protected $fillable = [
        'name',
        'email',
        'password',
        'role',
    ];

    public function canAccessPanel(Panel $panel): bool
    {
        return $this->role === 'master';
    }
}
