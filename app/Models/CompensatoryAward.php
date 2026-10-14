<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class CompensatoryAward extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return ['work_date' => 'date', 'evidence' => 'array'];
    }

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function day()
    {
        return $this->belongsTo(AttendanceDay::class, 'attendance_day_id');
    }
}
