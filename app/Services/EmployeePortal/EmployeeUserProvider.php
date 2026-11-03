<?php

namespace App\Services\EmployeePortal;

use App\Models\User;
use Illuminate\Auth\EloquentUserProvider;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Support\Facades\DB;

class EmployeeUserProvider extends EloquentUserProvider
{
    public function validateCredentials(Authenticatable $user, array $credentials): bool
    {
        if (! $user instanceof User || ! $user->canAccessEmployeePortal()) {
            return false;
        }

        if ($user->portal_enabled) {
            return parent::validateCredentials($user, $credentials);
        }

        // Bootstrap only after proving the device credential, under a row lock.
        return DB::transaction(function () use ($user, $credentials): bool {
            $current = User::lockForUpdate()->findOrFail($user->id);
            if (! $current->canAccessEmployeePortal()) {
                return false;
            }
            if ($current->portal_enabled) {
                $user->setRawAttributes($current->getAttributes(), true);

                return parent::validateCredentials($user, $credentials);
            }
            if (! hash_equals((string) $current->device_password, (string) ($credentials['password'] ?? ''))) {
                return false;
            }
            $current->forceFill(['password' => $current->device_password, 'portal_enabled' => true,
                'requires_password_change' => true])->save();
            $user->setRawAttributes($current->getAttributes(), true);

            return true;
        });
    }
}
