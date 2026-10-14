<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class EmployeeLeave extends Model
{
    protected $guarded = [];

    protected static function booted(): void
    {
        static::deleting(function ($leave) {
            if ($leave->balance_managed) {
                throw new \LogicException('Cancel leave to release credits; retain the audit record.');
            }
        });
    }

    protected function casts(): array
    {
        return ['leave_date' => 'date', 'balance_managed' => 'boolean', 'reviewed_at' => 'datetime'];
    }

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function leaveType()
    {
        return $this->belongsTo(LeaveType::class);
    }

    public function getHalfUnitsAttribute(): int
    {
        return $this->duration === 'half' ? 1 : 2;
    }
}
