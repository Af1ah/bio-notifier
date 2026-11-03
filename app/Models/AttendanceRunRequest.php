<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Stancl\Tenancy\Database\Concerns\CentralConnection;

class AttendanceRunRequest extends Model
{
    use CentralConnection;

    protected $guarded = [];

    public function scopePendingInOrder(Builder $query): Builder
    {
        return $query->whereIn('state', ['queued', 'running'])
            ->orderByRaw("CASE WHEN state = 'running' THEN 0 ELSE 1 END")
            ->orderBy('id');
    }

    protected function casts(): array
    {
        return ['user_ids' => 'array', 'filters' => 'array', 'from_date' => 'date', 'to_date' => 'date', 'cursor' => 'integer', 'failures' => 'integer'];
    }
}
