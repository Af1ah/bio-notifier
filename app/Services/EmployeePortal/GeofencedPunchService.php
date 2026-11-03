<?php

namespace App\Services\EmployeePortal;

use App\Models\EmployeePortalSetting;
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
            'kind' => 'required|in:in,out', 'request_id' => 'required|uuid',
            'photo' => 'required|string|max:1048600',
        ])->validate();
        $key = 'portal-punch:'.tenant('id').':'.$employee->id;
        if (RateLimiter::tooManyAttempts($key, 10)) {
            throw ValidationException::withMessages(['location' => 'Too many attempts. Please wait a minute.']);
        }
        RateLimiter::hit($key, 60);

        try {
            return DB::transaction(function () use ($employee, $data, $input) {
                $employee = User::lockForUpdate()->findOrFail($employee->id);
                abort_unless($employee->canAccessEmployeePortal() && ! $employee->requires_password_change, 403);
                $existing = PortalPunch::where('user_id', $employee->id)->where('request_id', $data['request_id'])->first();
                if ($existing) {
                    return $existing;
                }
                $setting = EmployeePortalSetting::query()->whereKey(1)->sharedLock()->first();
                if (! $setting) {
                    throw ValidationException::withMessages(['location' => 'Attendance settings are unavailable. Contact HR or Admin.']);
                }
                $location = null;
                $distance = null;
                $gps = Validator::make($input, [
                    'latitude' => 'required|numeric|between:-90,90',
                    'longitude' => 'required|numeric|between:-180,180',
                    'accuracy' => 'required|numeric|gt:0',
                    'captured_at' => 'required|numeric',
                ])->validate();
                if (abs(now()->getTimestampMs() - $gps['captured_at']) > 120000) {
                    throw ValidationException::withMessages(['location' => 'Your location has expired. Please try again.']);
                }
                if ($setting->geofencing_enabled) {
                    $selection = Validator::make($input, ['location_id' => 'required|integer'])->validate();
                    $location = WorkLocation::where('is_active', true)->sharedLock()->find($selection['location_id']);
                    if (! $location) {
                        throw ValidationException::withMessages(['location' => 'Choose an active work location.']);
                    }
                    $distance = $location->distanceTo((float) $gps['latitude'], (float) $gps['longitude']);
                    if ($distance + (float) $gps['accuracy'] > $location->radius_meters) {
                        throw ValidationException::withMessages(['location' => 'Move inside the work location or wait for a more accurate GPS reading.']);
                    }
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
                        'user_id' => $employee->id, 'work_location_id' => $location?->id, 'request_id' => $data['request_id'],
                        'kind' => $data['kind'], 'punched_at' => now(TenantTimezone::get()), 'photo_path' => $path, 'state' => 'pending',
                        'evidence' => ['source' => 'employee_portal', 'geofencing_enabled' => (bool) $setting->geofencing_enabled,
                            'location_id' => $location?->id, 'location_name' => $location?->name,
                            'branch_id' => $location?->branch_id, 'pin' => $employee->pin,
                            'latitude' => isset($gps['latitude']) ? (float) $gps['latitude'] : null,
                            'longitude' => isset($gps['longitude']) ? (float) $gps['longitude'] : null,
                            'accuracy_meters' => isset($gps['accuracy']) ? (float) $gps['accuracy'] : null,
                            'distance_meters' => $distance === null ? null : round($distance, 2),
                            'fence_latitude' => $location?->latitude, 'fence_longitude' => $location?->longitude,
                            'radius_meters' => $location?->radius_meters, 'captured_at' => $gps['captured_at'] ?? null],
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
