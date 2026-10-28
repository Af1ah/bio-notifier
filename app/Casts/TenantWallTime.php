<?php

namespace App\Casts;

use App\Services\Support\TenantTimezone;
use Carbon\Carbon;
use Illuminate\Contracts\Database\Eloquent\CastsAttributes;

// Existing attendance columns are local wall time. Audit/command timestamps remain UTC.
final class TenantWallTime implements CastsAttributes
{
    public function get($model, string $key, mixed $value, array $attributes): mixed
    {
        return $value === null ? null : Carbon::parse($value, TenantTimezone::get());
    }

    public function set($model, string $key, mixed $value, array $attributes): mixed
    {
        return $value === null ? null : ($value instanceof \DateTimeInterface ? $value->format('Y-m-d H:i:s') : Carbon::parse($value, TenantTimezone::get())->format('Y-m-d H:i:s'));
    }
}
