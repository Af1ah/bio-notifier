<?php

namespace App\Models;

use Illuminate\Foundation\Auth\User as Authenticatable;
use Filament\Models\Contracts\FilamentUser;
use Filament\Panel;

class Admin extends Authenticatable implements FilamentUser
{
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
