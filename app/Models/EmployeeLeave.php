<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class EmployeeLeave extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return ['leave_date' => 'date'];
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
