<?php

namespace App\Services\Payroll;

use App\Models\PayrollTemplate;
use App\Models\User;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class EmployeePayrollEditorService
{
    public function save(User $employee, array $data, ?User $actor = null): ?object
    {
        return DB::transaction(function () use ($employee, $data, $actor) {
            $employee = User::employees()->lockForUpdate()->findOrFail($employee->id); $profile = $employee->payrollProfile()->lockForUpdate()->first();
            if (empty($data['payroll_enabled'])) { if ($profile && $profile->payroll_enabled) { $profile->payroll_enabled = false; $profile->save(); } return $profile; }
            $template = ! empty($data['payroll_template_id']) ? PayrollTemplate::findOrFail($data['payroll_template_id']) : null;
            $overrides = array_values(array_intersect($data['template_overrides'] ?? [], PayrollProfileHistoryService::MONEY));
            $settings = $template ? Arr::only($template->settings, array_merge(PayrollProfileHistoryService::MONEY, ['pay_basis', 'currency'])) : [];
            foreach ($overrides as $field) if (array_key_exists($field, $data)) $settings[$field] = (int) $data[$field];
            if (! $template) $settings = Arr::only($data, array_merge(PayrollProfileHistoryService::MONEY, ['pay_basis', 'currency']));
            if (($settings['pay_basis'] ?? 'monthly') !== 'monthly') foreach (['housing_allowance_minor','transport_allowance_minor','other_allowance_minor','fixed_deduction_minor'] as $field) $settings[$field] = 0;
            $settings += Arr::only($data, ['effective_from','employment_starts_on','employment_ends_on']);
            $settings += ['payroll_enabled' => true, 'payroll_template_id' => $template?->id, 'template_overrides' => $template ? $overrides : [], 'template_snapshot' => $template ? ['template' => $template->toArray(), 'applied_by_user_id' => $actor?->id, 'applied_at' => now()->toIso8601String()] : null];
            $profile ??= $employee->payrollProfile()->make(); $profile->fill($settings); $profile->save(); app(PayrollProfileHistoryService::class)->afterSave($profile, $actor); return $profile;
        }, 3);
    }
}
