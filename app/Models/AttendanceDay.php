<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class AttendanceDay extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return ['work_date' => 'date', 'calculated_at' => 'datetime', 'stale_at' => 'datetime', 'explanation' => 'array'];
    }

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function occurrences()
    {
        return $this->hasMany(AttendanceOccurrence::class);
    }

    public function approvals()
    {
        return $this->hasMany(AttendanceApproval::class);
    }

    public function revisions()
    {
        return $this->hasMany(AttendanceDayRevision::class);
    }
}
