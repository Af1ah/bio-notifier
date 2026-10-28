<?php

namespace App\Services\Devices;

use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Storage;

final class PrivateBiometricPhoto
{
    private const PREFIX = 'BIOPHOTO-ENC1:';

    private static function path(string $path): void
    {
        if (! str_starts_with($path, 'biometric-photos/') || str_contains($path, '..')) {
            throw new \RuntimeException('Invalid private photo path.');
        }
    }

    public static function validate(string $bytes): void
    {
        $image = @getimagesizefromstring($bytes);
        if (strlen($bytes) > 786432 || ! is_array($image) || ($image['mime'] ?? null) !== 'image/jpeg') {
            throw new \RuntimeException('Invalid private JPEG.');
        }
    }

    public static function read(string $path): string
    {
        self::path($path);
        $disk = Storage::disk('local');
        if (! $disk->exists($path) || $disk->size($path) > 2200000) {
            throw new \RuntimeException('Private photo unavailable.');
        }
        $bytes = $disk->get($path);
        if (str_starts_with($bytes, self::PREFIX)) {
            $bytes = base64_decode(Crypt::decryptString(substr($bytes, strlen(self::PREFIX))), true);
            if ($bytes === false) {
                throw new \RuntimeException('Invalid private photo content.');
            }
        }
        self::validate($bytes);

        return $bytes;
    }

    public static function put(string $path, string $bytes): void
    {
        self::path($path);
        self::validate($bytes);
        if (! Storage::disk('local')->put($path, self::PREFIX.Crypt::encryptString(base64_encode($bytes)), ['visibility' => 'private'])) {
            throw new \RuntimeException('Unable to save private photo.');
        }
    }

    public static function protect(string $path): void
    {
        self::path($path);
        $disk = Storage::disk('local');
        if ($disk->exists($path) && ! str_starts_with($disk->get($path), self::PREFIX)) {
            if (! $disk->put($path, self::PREFIX.Crypt::encryptString(base64_encode($disk->get($path))), ['visibility' => 'private'])) {
                throw new \RuntimeException('Unable to protect private photo.');
            }
        }
    }
}
