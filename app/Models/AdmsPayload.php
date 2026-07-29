<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class AdmsPayload extends Model
{
    protected $guarded = [];

    // Overriding the connection to always point to the central database,
    // regardless of the SQL driver (mysql, pgsql, etc.)
    public function getConnectionName()
    {
        return config('tenancy.database.central_connection') ?? config('database.default');
    }

    public function tenant()
    {
        return $this->belongsTo(Organisation::class, 'tenant_id');
    }
}
