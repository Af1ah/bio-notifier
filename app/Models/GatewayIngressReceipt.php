<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Stancl\Tenancy\Database\Concerns\CentralConnection;

class GatewayIngressReceipt extends Model
{
    use CentralConnection;

    protected $table = 'gateway_ingress_receipts';

    protected $guarded = [];

    public $incrementing = false;

    protected $keyType = 'string';

    protected function casts(): array
    {
        return ['payload' => 'encrypted:array', 'leased_until' => 'datetime', 'next_attempt_at' => 'datetime'];
    }
}
