<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

class PayrollTemplate extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return ['settings' => 'array', 'effective_from' => 'date'];
    }

    public function branch()
    {
        return $this->belongsTo(Branch::class);
    }

    protected static function booted(): void
    {
        static::saving(function ($template) {
            if ($template->exists && EmployeePayrollProfile::where('payroll_template_id', $template->id)->exists() && $template->isDirty()) {
                throw ValidationException::withMessages(['name' => 'This template has been applied. Create a new template for changed rates.']);
            }
            Validator::make($template->toArray(), ['name' => 'required|string|max:120', 'branch_id' => 'nullable|exists:branches,id', 'role' => 'nullable|string|max:255', 'effective_from' => 'required|date', 'settings' => 'required|array', 'settings.pay_basis' => 'required|in:monthly,daily,hourly', 'settings.currency' => 'required|string|size:3|regex:/^[A-Z]{3}$/', 'settings.basic_salary_minor' => 'required|integer|min:0|max:100000000', 'settings.overtime_hourly_rate_minor' => 'required|integer|min:0|max:100000000', 'settings.housing_allowance_minor' => 'required|integer|min:0|max:100000000', 'settings.transport_allowance_minor' => 'required|integer|min:0|max:100000000', 'settings.other_allowance_minor' => 'required|integer|min:0|max:100000000', 'settings.fixed_deduction_minor' => 'required|integer|min:0|max:100000000'])->validate();
        });
    }
}
