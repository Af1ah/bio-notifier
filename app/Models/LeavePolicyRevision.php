<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class LeavePolicyRevision extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return ['rules' => 'array', 'effective_from' => 'date'];
    }

    public function policy()
    {
        return $this->belongsTo(LeavePolicy::class, 'leave_policy_id');
    }

    protected static function booted(): void
    {
        static::updating(fn () => throw new \LogicException('Leave evidence is immutable; use a reviewed reversal.'));
        static::deleting(fn () => throw new \LogicException('Leave evidence must be retained.'));
    }
}
