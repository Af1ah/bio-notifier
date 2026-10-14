<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Validation\ValidationException;

class EmployeePayrollProfile extends Model
{
    protected $guarded = [];

    protected static function booted(): void
    {
        static::saving(function ($profile) {
            if ($profile->employment_starts_on && $profile->employment_ends_on && $profile->employment_ends_on->lt($profile->employment_starts_on)) {
                throw ValidationException::withMessages(['employment_ends_on' => 'Employment end must be on or after employment start.']);
            }
        });
    }

    protected function casts(): array
    {
        return [
            'payroll_enabled' => 'boolean',
            'effective_from' => 'date', 'employment_starts_on' => 'date', 'employment_ends_on' => 'date',
        ];
    }

    public function user()
    {
        return $this->belongsTo(User::class);
    }
}
