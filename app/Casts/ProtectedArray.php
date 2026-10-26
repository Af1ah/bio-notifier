<?php

namespace App\Casts;

use Illuminate\Contracts\Database\Eloquent\CastsAttributes;
use Illuminate\Support\Facades\Crypt;

final class ProtectedArray implements CastsAttributes
{
    public static function decode(mixed $value): ?array
    {
        if ($value === null) {
            return null;
        }
        $data = is_array($value) ? $value : json_decode($value, true, 512, JSON_THROW_ON_ERROR);
        // Older command writers saved one extra JSON layer. Unwrap it once only.
        if (is_string($data)) {
            $data = json_decode($data, true, 512, JSON_THROW_ON_ERROR);
        }
        if (isset($data['_encrypted_v1'])) {
            $data = json_decode(Crypt::decryptString($data['_encrypted_v1']), true, 512, JSON_THROW_ON_ERROR);
        }
        if (! is_array($data)) {
            throw new \RuntimeException('Invalid protected array.');
        }

        return $data;
    }

    public static function encode(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }

        return json_encode(['_encrypted_v1' => Crypt::encryptString(json_encode($value, JSON_THROW_ON_ERROR))], JSON_THROW_ON_ERROR);
    }

    public function get($model, string $key, mixed $value, array $attributes): mixed
    {
        return self::decode($value);
    }

    public function set($model, string $key, mixed $value, array $attributes): mixed
    {
        return self::encode($value);
    }
}
