<?php

namespace App\Models;

use App\Casts\ProtectedArray;
use Illuminate\Database\Eloquent\Model;

class DeviceRosterEntry extends Model
{
    protected $table = 'gateway_device_users';

    protected $guarded = [];

    protected function casts(): array
    {
        return ['access_settings' => ProtectedArray::class, 'access_received_at' => 'datetime'];
    }
}
