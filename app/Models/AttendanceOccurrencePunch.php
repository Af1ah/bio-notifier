<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class AttendanceOccurrencePunch extends Model
{
    protected $guarded = [];

    public function occurrence()
    {
        return $this->belongsTo(AttendanceOccurrence::class, 'attendance_occurrence_id');
    }

    public function attendanceLog()
    {
        return $this->belongsTo(AttendanceLog::class);
    }
}
