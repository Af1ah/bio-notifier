<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class EmployeePortalSetting extends Model
{
    protected $fillable = ['geofencing_enabled'];

    protected function casts(): array
    {
        return ['geofencing_enabled' => 'boolean'];
    }

    public static function geofencingEnabled(): bool
    {
        // Missing configuration must never silently disable an existing fence.
        return (bool) (static::query()->whereKey(1)->value('geofencing_enabled') ?? true);
    }
}
