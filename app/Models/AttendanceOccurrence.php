<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class AttendanceOccurrence extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return ['window_starts_at' => 'datetime', 'window_ends_at' => 'datetime', 'first_in_at' => 'datetime', 'last_out_at' => 'datetime', 'assumed_out_at' => 'datetime', 'exception_flags' => 'array', 'explanation' => 'array'];
    }

    public function attendanceDay()
    {
        return $this->belongsTo(AttendanceDay::class);
    }

    public function schedule()
    {
        return $this->belongsTo(Schedule::class);
    }

    public function ruleRevision()
    {
        return $this->belongsTo(ShiftRuleRevision::class, 'shift_rule_revision_id');
    }

    public function punches()
    {
        return $this->hasMany(AttendanceOccurrencePunch::class);
    }
}
