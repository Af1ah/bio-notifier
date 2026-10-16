<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Validation\ValidationException;

class LeaveType extends Model
{
    protected $guarded = [];

    protected static function booted(): void
    {
        static::saving(function ($type) {
            if ($type->is_paid ?? true) {
                $type->deduction_method = null;
                $type->deduction_amount_minor = null;
            }
        });
        static::updating(function ($type) {
            if ($type->isDirty('is_paid') && $type->getConnection()->getSchemaBuilder()->hasTable('leave_policies') && LeavePolicy::where('leave_type_id', $type->id)->exists()) {
                throw ValidationException::withMessages(['is_paid' => 'Enrolled leave policies retain their paid classification. Create a separate unpaid leave type.']);
            }
        });
    }

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
