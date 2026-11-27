<?php

namespace App\Services\EmployeePortal;

use Illuminate\Support\Facades\Process;
use Illuminate\Validation\ValidationException;

final class PunchPhoto
{
    public function prepare(string $photo): array
    {
        $prefix = 'data:image/jpeg;base64,';
        $bytes = str_starts_with($photo, $prefix) ? base64_decode(substr($photo, strlen($prefix)), true) : false;
        $size = $bytes ? @getimagesizefromstring($bytes) : false;
        if (! $bytes || strlen($bytes) > 786432 || ! $size || ($size['mime'] ?? '') !== 'image/jpeg'
            || $size[0] < 160 || $size[1] < 120 || $size[0] > 1600 || $size[1] > 1600) {
            throw ValidationException::withMessages(['photo' => __('employee-punch.photoUnreadable')]);
        }
        $image = @imagecreatefromstring($bytes);
        if (! $image) {
            throw ValidationException::withMessages(['photo' => __('employee-punch.photoUnreadable')]);
        }
        ob_start();
        imagejpeg($image, null, 85);
        $bytes = ob_get_clean();
        imagedestroy($image);
        if (strlen($bytes) > 786432) {
            throw ValidationException::withMessages(['photo' => __('employee-punch.photoSmaller')]);
        }

        $validation = null;
        if (config('services.punch_photo.enabled', true)) {
            $binary = config('services.punch_photo.binary');
            if (! is_string($binary) || ! is_executable($binary)) {
                $this->reject('unavailable');
            }
            try {
                $process = Process::input($bytes)->timeout(3)->run([$binary]);
            } catch (\Throwable) {
                $this->reject('unavailable');
            }
            if ($process->failed() || strlen($process->output()) > 4096) {
                $this->reject('unavailable');
            }
            $validation = json_decode($process->output(), true);
            if (! is_array($validation) || ($validation['version'] ?? null) !== 'pigo-1.4.6-v1') {
                $this->reject('unavailable');
            }
            if (($validation['ok'] ?? null) !== true || ($validation['code'] ?? null) !== 'accepted' || ($validation['faces'] ?? null) !== 1) {
                $this->reject($validation['code'] ?? 'unavailable');
            }
            $validation = ['faces' => 1, 'version' => $validation['version']];
        }

        return compact('bytes', 'validation');
    }

    private function reject(mixed $code): never
    {
        $code = in_array($code, ['no_face', 'multiple_faces', 'face_too_small', 'face_cut_off', 'poor_lighting', 'blurry', 'face_not_clear'], true)
            ? $code : 'unavailable';
        throw ValidationException::withMessages(['photo' => __('employee-punch.face_'.$code)]);
    }
}
