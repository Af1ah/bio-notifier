<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class FrappeSetting extends Model
{
    protected $connection = 'tenant';

    protected $guarded = [];

    protected $hidden = ['api_key', 'api_secret'];

    public $timestamps = false;

    protected $casts = [
        'enabled' => 'boolean',
        'cooldown_until' => 'immutable_datetime',
        'api_key' => 'encrypted',
        'api_secret' => 'encrypted',
    ];
}
