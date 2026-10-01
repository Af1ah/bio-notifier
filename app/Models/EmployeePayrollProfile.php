<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class EmployeePayrollProfile extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'payroll_enabled' => 'boolean',
            'effective_from' => 'date',
        ];
    }

    public function user()
    {
        return $this->belongsTo(User::class);
    }
}
