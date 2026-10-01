<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class LeaveType extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'is_paid' => 'boolean',
            'active' => 'boolean',
        ];
    }

    public function leaves()
    {
        return $this->hasMany(EmployeeLeave::class);
    }

    public function getAnnualAllowanceDaysAttribute(): float
    {
        return $this->annual_allowance_half_units / 2;
    }
}
