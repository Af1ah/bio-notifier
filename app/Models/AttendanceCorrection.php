<?php

namespace App\Models;

use App\Casts\TenantWallTime;
use Illuminate\Database\Eloquent\Model;

class AttendanceCorrection extends Model
{
    protected $guarded = [];

    protected static function booted(): void
    {
        static::updating(function ($correction) {
            if ($correction->isDirty(['attendance_day_id', 'attendance_occurrence_id', 'proposed_in_at', 'proposed_out_at', 'proposed_status', 'reason', 'created_by_user_id'])) {
                throw new \LogicException('Correction source is immutable. Submit a new correction.');
            }
        });
        static::deleting(fn () => throw new \LogicException('Manual corrections are retained as audit evidence.'));
    }

    protected function casts(): array
    {
        return ['proposed_in_at' => TenantWallTime::class, 'proposed_out_at' => TenantWallTime::class];
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
