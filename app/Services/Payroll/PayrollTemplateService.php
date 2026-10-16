<?php

namespace App\Services\Payroll;

use App\Models\PayrollTemplate;
use App\Models\SalarySlip;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

class PayrollTemplateService
{
    public function preview(PayrollTemplate $template, array $ids, User $actor, ?string $effectiveFrom = null, array $overrides = []): array
    {
        abort_unless((int) $actor->privilege === 14, 403);
        Validator::make(['users' => $ids], ['users' => 'required|array|min:1|max:1000', 'users.*' => 'integer|distinct|exists:users,id'])->validate();
        sort($ids);

        $effectiveFrom ??= $template->effective_from->toDateString();
        $rows = [];
        foreach ($ids as $id) {
            $user = User::employees()->with('payrollProfile')->findOrFail($id);
            if (($template->branch_id && (int) $template->branch_id !== (int) $user->branch_id) || ($template->role && $template->role !== $user->group)) throw ValidationException::withMessages(['users' => 'An employee does not match the template branch or designation.']);
            $profile = $user->payrollProfile;
            $after = array_intersect_key($template->settings, array_flip(array_merge(PayrollProfileHistoryService::MONEY, ['currency', 'pay_basis'])));
            foreach (array_intersect($overrides, PayrollProfileHistoryService::MONEY) as $field) $after[$field] = $profile?->$field ?? 0;
            if (($after['pay_basis'] ?? 'monthly') !== 'monthly') foreach (['housing_allowance_minor','transport_allowance_minor','other_allowance_minor','fixed_deduction_minor'] as $field) $after[$field] = 0;
            $rows[] = ['id' => $user->id, 'name' => $user->name, 'status' => $profile?->payroll_enabled ? 'will_update' : 'payroll_disabled', 'before' => $profile?->only(array_merge(PayrollProfileHistoryService::MONEY, ['currency','pay_basis'])) ?? [], 'after' => $after];
        }
        return ['effective_from' => $effectiveFrom, 'rows' => $rows, 'signature' => hash('sha256', json_encode([$template->id, $effectiveFrom, $overrides, $rows]))];
    }

    public function apply(PayrollTemplate $template, array $ids, User $actor, ?string $effectiveFrom = null, array $overrides = [], ?string $signature = null): array
    {
        $preview = $this->preview($template, $ids, $actor, $effectiveFrom, $overrides);
        if ($signature && ! hash_equals($signature, $preview['signature'])) throw ValidationException::withMessages(['users' => 'The payroll preview is out of date. Preview it again before applying.']);
        return DB::transaction(function () use ($template, $ids, $actor, $preview, $overrides) {
            $result = ['applied' => 0, 'disabled' => 0];
            foreach ($ids as $id) {
                $user = User::employees()->lockForUpdate()->findOrFail($id);
                $profile = $user->payrollProfile()->lockForUpdate()->first();
                if (! $profile?->payroll_enabled) { $result['disabled']++; continue; }
                app(EmployeePayrollEditorService::class)->save($user, ['payroll_enabled' => true, 'payroll_template_id' => $template->id, 'template_overrides' => $overrides, 'effective_from' => $preview['effective_from'], 'employment_starts_on' => $profile->employment_starts_on, 'employment_ends_on' => $profile->employment_ends_on] + $profile->only(PayrollProfileHistoryService::MONEY), $actor);
                $result['applied']++;
            }

            return $result;
        }, 3);
    }
}
