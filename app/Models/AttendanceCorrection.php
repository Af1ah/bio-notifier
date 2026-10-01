<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class AttendanceCorrection extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return ['proposed_in_at' => 'datetime', 'proposed_out_at' => 'datetime'];
    }

    public function attendanceDay()
    {
        return $this->belongsTo(AttendanceDay::class);
    }

    public function occurrence()
    {
        return $this->belongsTo(AttendanceOccurrence::class, 'attendance_occurrence_id');
    }
}
