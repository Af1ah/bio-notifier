<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Stancl\Tenancy\Database\Concerns\CentralConnection;

class GatewayBindingVersion extends Model
{
    use CentralConnection;

    protected $guarded = [];

    protected function casts(): array
    {
        return ['activated_at' => 'datetime'];
    }
}
