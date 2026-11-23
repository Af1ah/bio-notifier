<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Stancl\Tenancy\Database\Concerns\CentralConnection;

class OrganisationBackupRun extends Model
{
    use CentralConnection, HasUuids;

    protected $guarded = [];

    protected $casts = [
        'bytes' => 'integer',
        'available_at' => 'immutable_datetime',
        'verified_at' => 'immutable_datetime',
    ];
}
