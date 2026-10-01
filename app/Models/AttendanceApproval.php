<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class AttendanceApproval extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return ['proposal' => 'array', 'decided_at' => 'datetime', 'superseded_at' => 'datetime'];
    }

    public function attendanceDay()
    {
        return $this->belongsTo(AttendanceDay::class);
    }

    public function occurrence()
    {
        return $this->belongsTo(AttendanceOccurrence::class, 'attendance_occurrence_id');
    }

    public function requestedBy()
    {
        return $this->belongsTo(User::class, 'requested_by_user_id');
    }

    public function decidedBy()
    {
        return $this->belongsTo(User::class, 'decided_by_user_id');
    }
}
