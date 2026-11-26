<?php

namespace App\Http\Controllers;

use App\Models\PortalPunch;
use App\Services\Devices\PrivateBiometricPhoto;
use App\Services\EmployeePortal\DepartmentReview;

class PunchPhotoController
{
    public function __invoke(int $punch)
    {
        $manager = auth('web')->user()?->fresh();
        $employee = auth('employee')->user()?->fresh();
        $isManager = (bool) ($manager?->is_enabled && $manager->hasManagementAccess());
        abort_unless($isManager
            || $employee?->canAccessEmployeePortal() && ! $employee->requires_password_change, 403);
        $record = PortalPunch::query()->when(! $isManager, fn ($q) => $q->where(fn ($scope) => $scope->where('user_id', $employee->id)
            ->orWhereIn('user_id', DepartmentReview::employees($employee)->select('users.id'))))->findOrFail($punch);
        try {
            $bytes = PrivateBiometricPhoto::read($record->photo_path);
        } catch (\RuntimeException $exception) {
            abort(404);
        }

        return response($bytes, 200, ['Content-Type' => 'image/jpeg', 'Cache-Control' => 'private, no-store, max-age=0',
            'X-Content-Type-Options' => 'nosniff', 'Content-Disposition' => 'inline; filename="punch-photo.jpg"']);
    }
}
