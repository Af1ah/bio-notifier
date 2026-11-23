<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Stancl\Tenancy\Database\Concerns\CentralConnection;

class OrganisationBackupSetting extends Model
{
    use CentralConnection;

    protected $primaryKey = 'organisation_id';

    protected $keyType = 'string';

    public $incrementing = false;

    public $timestamps = false;

    protected $guarded = [];

    protected $hidden = ['refresh_token'];

    protected $casts = ['next_run_at' => 'immutable_datetime', 'refresh_token' => 'encrypted'];
}
