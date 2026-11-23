<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class WorkLocation extends Model
{
    protected $fillable = ['name', 'branch_id', 'latitude', 'longitude', 'radius_meters', 'is_active'];

    protected function casts(): array
    {
        return ['latitude' => 'float', 'longitude' => 'float', 'radius_meters' => 'integer', 'is_active' => 'boolean'];
    }

    public function branch()
    {
        return $this->belongsTo(Branch::class);
    }

    public function getGoogleMapsUrlAttribute(): string
    {
        return 'https://www.google.com/maps/search/?api=1&query='.rawurlencode($this->latitude.','.$this->longitude);
    }

    public function distanceTo(float $latitude, float $longitude): float
    {
        $a = sin(deg2rad($latitude - $this->latitude) / 2) ** 2
            + cos(deg2rad($this->latitude)) * cos(deg2rad($latitude)) * sin(deg2rad($longitude - $this->longitude) / 2) ** 2;

        return 6371000 * 2 * asin(sqrt(min(1, max(0, $a))));
    }
}
