<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class LeaveCreditLot extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return ['available_on' => 'date', 'expires_on' => 'date', 'evidence' => 'array'];
    }

    public function policyRevision()
    {
        return $this->belongsTo(LeavePolicyRevision::class, 'leave_policy_revision_id');
    }

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function leaveType()
    {
        return $this->belongsTo(LeaveType::class);
    }

    public function entries()
    {
        return $this->hasMany(LeaveLedgerEntry::class);
    }

    protected static function booted(): void
    {
        static::updating(fn () => throw new \LogicException('Leave evidence is immutable; use a reviewed reversal.'));
        static::deleting(fn () => throw new \LogicException('Leave evidence must be retained.'));
    }
}
