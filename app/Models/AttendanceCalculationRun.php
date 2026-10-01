<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class AttendanceCalculationRun extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return ['from_date' => 'date', 'to_date' => 'date', 'filters' => 'array'];
    }
}
