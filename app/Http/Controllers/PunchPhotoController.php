<?php

namespace App\Http\Controllers;

use App\Models\PortalPunch;
use App\Services\Devices\PrivateBiometricPhoto;

class PunchPhotoController
{
    public function __invoke(int $punch)
    {
        $manager = auth('web')->user()?->fresh();
        $employee = auth('employee')->user()?->fresh();
        $isManager = (bool) ($manager?->is_enabled && $manager->hasManagementAccess());
        abort_unless($isManager
            || $employee?->canAccessEmployeePortal() && ! $employee->requires_password_change, 403);
        $record = PortalPunch::query()->when(! $isManager, fn ($q) => $q->where('user_id', $employee->id))->findOrFail($punch);
        try {
            $bytes = PrivateBiometricPhoto::read($record->photo_path);
        } catch (\RuntimeException $exception) {
            abort(404);
        }

        return response($bytes, 200, ['Content-Type' => 'image/jpeg', 'Cache-Control' => 'private, no-store, max-age=0',
            'X-Content-Type-Options' => 'nosniff', 'Content-Disposition' => 'inline; filename="punch-photo.jpg"']);
    }
}
