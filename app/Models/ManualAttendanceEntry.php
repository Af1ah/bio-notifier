<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ManualAttendanceEntry extends Model
{
    protected $guarded = [];

    protected static function booted(): void
    {
        static::updating(fn () => throw new \LogicException('Manual attendance source is immutable; submit a correction.'));
        static::deleting(fn () => throw new \LogicException('Manual attendance source is retained as audit evidence.'));
    }

    protected function casts(): array
    {
        return ['work_date' => 'date', 'in_at' => \App\Casts\TenantWallTime::class, 'out_at' => \App\Casts\TenantWallTime::class];
    }

    public function schedule()
    {
        return $this->belongsTo(Schedule::class);
    }

    public function ruleRevision()
    {
        return $this->belongsTo(ShiftRuleRevision::class, 'shift_rule_revision_id');
    }
}
