<?php

namespace App\Services\EmployeePortal;

use App\Models\PortalPunch;
use App\Models\User;
use App\Models\WorkLocation;
use App\Services\Devices\PrivateBiometricPhoto;
use App\Services\Support\TenantTimezone;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

final class GeofencedPunchService
{
    public function record(array $input): PortalPunch
    {
        $employee = PortalAccess::employee();
        $data = Validator::make($input, [
            'location_id' => 'required|integer', 'kind' => 'required|in:in,out', 'request_id' => 'required|uuid',
            'latitude' => 'required|numeric|between:-90,90', 'longitude' => 'required|numeric|between:-180,180',
            'accuracy' => 'required|numeric|gt:0|max:10000', 'captured_at' => 'required|numeric',
            'photo' => 'required|string|max:1048600',
        ])->validate();
        $key = 'portal-punch:'.tenant('id').':'.$employee->id;
        if (RateLimiter::tooManyAttempts($key, 10)) {
            throw ValidationException::withMessages(['location' => 'Too many attempts. Please wait a minute.']);
        }
        RateLimiter::hit($key, 60);

        try {
            return DB::transaction(function () use ($employee, $data) {
                $employee = User::lockForUpdate()->findOrFail($employee->id);
                abort_unless($employee->canAccessEmployeePortal() && ! $employee->requires_password_change, 403);
                $existing = PortalPunch::where('user_id', $employee->id)->where('request_id', $data['request_id'])->first();
                if ($existing) {
                    return $existing;
                }
                if (abs(now()->getTimestampMs() - $data['captured_at']) > 120000) {
                    throw ValidationException::withMessages(['location' => 'Your location has expired. Please try again.']);
                }
                $location = WorkLocation::where('is_active', true)->sharedLock()->find($data['location_id']);
                if (! $location) {
                    throw ValidationException::withMessages(['location' => 'Choose an active work location.']);
                }
                $distance = $location->distanceTo((float) $data['latitude'], (float) $data['longitude']);
                if ($distance + (float) $data['accuracy'] > $location->radius_meters) {
                    throw ValidationException::withMessages(['location' => 'Move inside the work location or wait for a more accurate GPS reading.']);
                }

                $prefix = 'data:image/jpeg;base64,';
                $bytes = str_starts_with($data['photo'], $prefix) ? base64_decode(substr($data['photo'], strlen($prefix)), true) : false;
                $size = $bytes ? @getimagesizefromstring($bytes) : false;
                if (! $bytes || strlen($bytes) > 786432 || ! $size || ($size['mime'] ?? '') !== 'image/jpeg'
                    || $size[0] < 160 || $size[1] < 120 || $size[0] > 1600 || $size[1] > 1600) {
                    throw ValidationException::withMessages(['photo' => 'Take a clear camera photo and try again.']);
                }
                $image = @imagecreatefromstring($bytes);
                if (! $image) {
                    throw ValidationException::withMessages(['photo' => 'The camera photo could not be read. Please retake it.']);
                }
                ob_start();
                imagejpeg($image, null, 85);
                $bytes = ob_get_clean();
                imagedestroy($image);
                if (strlen($bytes) > 786432) {
                    throw ValidationException::withMessages(['photo' => 'Retake a smaller camera photo and try again.']);
                }
                $path = 'biometric-photos/punches/'.Str::uuid().'.jpg';
                PrivateBiometricPhoto::put($path, $bytes);
                try {
                    return PortalPunch::create([
                        'user_id' => $employee->id, 'work_location_id' => $location->id, 'request_id' => $data['request_id'],
                        'kind' => $data['kind'], 'punched_at' => now(TenantTimezone::get()), 'photo_path' => $path, 'state' => 'pending',
                        'evidence' => ['source' => 'employee_portal', 'location_id' => $location->id, 'location_name' => $location->name,
                            'branch_id' => $location->branch_id, 'pin' => $employee->pin,
                            'latitude' => (float) $data['latitude'], 'longitude' => (float) $data['longitude'],
                            'accuracy_meters' => (float) $data['accuracy'], 'distance_meters' => round($distance, 2),
                            'fence_latitude' => $location->latitude, 'fence_longitude' => $location->longitude,
                            'radius_meters' => $location->radius_meters, 'captured_at' => $data['captured_at']],
                    ]);
                } catch (\Throwable $exception) {
                    Storage::disk('local')->delete($path);
                    throw $exception;
                }
            });
        } catch (UniqueConstraintViolationException $exception) {
            $existing = PortalPunch::where('user_id', $employee->id)->where('request_id', $data['request_id'])->first();
            if ($existing) {
                return $existing;
            }
            throw ValidationException::withMessages(['location' => 'A punch was already recorded at this time. Wait a moment before punching again.']);
        }
    }
}
