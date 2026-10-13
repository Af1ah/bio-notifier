<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Stancl\Tenancy\Database\Concerns\CentralConnection;

class GatewayDeviceBinding extends Model
{
    use CentralConnection;

    protected $table = 'gateway_device_bindings';

    protected $guarded = [];

    protected function casts(): array
    {
        return ['capabilities' => 'array', 'enabled' => 'boolean', 'synced_at' => 'datetime'];
    }
}
