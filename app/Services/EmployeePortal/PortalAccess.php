<?php

namespace App\Services\EmployeePortal;

use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\Rules\Password;
use Illuminate\Validation\ValidationException;

final class PortalAccess
{
    public function reset(User $actor, User $employee): void
    {
        $actor = $actor->fresh();
        abort_unless($actor?->hasManagementAccess(), 403);
        DB::transaction(function () use ($employee) {
            $employee = User::employees()->lockForUpdate()->findOrFail($employee->id);
            abort_if($employee->hasManagementAccess(), 403);
            Validator::make(['email' => $employee->email, 'device_password' => $employee->device_password], [
                'email' => 'required|email', 'device_password' => 'required|string|min:6',
            ], ['device_password.min' => 'An Admin must set a device password with at least 6 characters first.'])->validate();
            $employee->forceFill(['password' => $employee->device_password, 'portal_enabled' => true,
                'requires_password_change' => true, 'remember_token' => Str::random(60)])->save();
        });
    }

    public function changePassword(User $employee, array $data): void
    {
        $currentRequired = ! $employee->fresh()->requires_password_change;
        $data = Validator::make($data, ['current_password' => $currentRequired ? 'required|current_password:employee' : 'nullable', 'password' => ['required', 'string', 'confirmed', Password::min(8)->mixedCase()->numbers()->symbols()]], [
            'password.min' => 'Use at least 8 characters, including uppercase, lowercase, a number and a symbol.',
        ])->validate();
        DB::transaction(function () use ($employee, $data) {
            $employee = User::lockForUpdate()->findOrFail($employee->id);
            abort_unless($employee->canAccessEmployeePortal(), 403);
            if (Hash::check($data['password'], $employee->password) || hash_equals((string) $employee->device_password, $data['password'])) {
                throw ValidationException::withMessages(['password' => 'Choose a new password different from your current and device passwords.']);
            }
            $employee->forceFill(['password' => $data['password'], 'requires_password_change' => false, 'remember_token' => Str::random(60)])->save();
        });
    }

    public static function employee(bool $allowPasswordChange = false): User
    {
        $employee = auth('employee')->user()?->fresh();
        abort_unless($employee instanceof User && $employee->canAccessEmployeePortal() && ($allowPasswordChange || ! $employee->requires_password_change), 403);

        return $employee;
    }
}
