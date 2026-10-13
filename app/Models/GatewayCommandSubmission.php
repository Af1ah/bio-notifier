<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Stancl\Tenancy\Database\Concerns\CentralConnection;

class GatewayCommandSubmission extends Model
{
    use CentralConnection;

    protected $table = 'gateway_command_submissions';

    protected $guarded = [];

    public $incrementing = false;

    protected $keyType = 'string';

    protected function casts(): array
    {
        return ['expires_at' => 'datetime', 'leased_until' => 'datetime', 'next_attempt_at' => 'datetime'];
    }
}
