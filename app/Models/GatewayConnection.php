<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Stancl\Tenancy\Database\Concerns\CentralConnection;

class GatewayConnection extends Model
{
    use CentralConnection;

    protected $table = 'gateway_connections';

    protected $guarded = [];

    protected $hidden = ['management_token', 'events_token'];

    protected function casts(): array
    {
        return ['management_token' => 'encrypted', 'events_token' => 'encrypted', 'enabled' => 'boolean', 'last_healthy_at' => 'datetime'];
    }
}
