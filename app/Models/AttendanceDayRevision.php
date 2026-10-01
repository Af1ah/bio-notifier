<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class AttendanceDayRevision extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return ['snapshot' => 'array', 'had_approved_attendance' => 'boolean', 'had_approved_overtime' => 'boolean', 'superseded_at' => 'datetime'];
    }

    public function attendanceDay()
    {
        return $this->belongsTo(AttendanceDay::class);
    }
}
