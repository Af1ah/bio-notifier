<?php

namespace App\Services\Devices;

final class BiometricCredentials
{
    public static function inferMajorVersion(string $type, string $tmp, ?string $explicit = null): ?string
    {
        if (filled($explicit)) {
            return (string) $explicit;
        }
        if ($type === 'finger' || $type === '1') {
            $raw = base64_decode(trim($tmp), true);
            if ($raw !== false && strlen($raw) >= 6) {
                $magic = substr($raw, 2, 4);
                if ($magic === 'SS20' || $magic === 'SS21') {
                    return '10';
                }
                if (substr($raw, 0, 4) === 'ZKFP') {
                    return '9';
                }
            }

            return '10';
        }

        return null;
    }

    public static function remember(array $stored, array $template, string $serial, string $at): array
    {
        $bioType = $template['bio_type'] ?? (isset($template['type']) && $template['type'] === 'face' ? '2' : ((isset($template['type']) && $template['type'] === 'finger') ? '1' : null));
        $majorVer = $template['major_ver'] ?? self::inferMajorVersion((string) ($template['type'] ?? $bioType ?? ''), (string) ($template['tmp'] ?? ''));
        $identity = [$serial, $bioType ?? $template['type'], $template['slot'], $template['index'] ?? '0',
            $majorVer ?? '', $template['minor_ver'] ?? '', $template['format'] ?? ''];
        $key = 'credential:'.hash('sha256', json_encode($identity, JSON_THROW_ON_ERROR));
        $stored[$key] = array_filter([
            'slot' => (string) $template['slot'], 'source_serial' => $serial, 'observed_at' => $at,
            'size' => $template['size'] ?? null, 'valid' => $template['valid'] ?? null, 'duress' => $template['duress'] ?? null,
            'sha256' => hash('sha256', $template['tmp']), 'byte_length' => strlen($template['tmp']),
            'tmp' => $template['tmp'], 'bio_type' => $bioType, 'index' => $template['index'] ?? null,
            'major_ver' => $majorVer, 'minor_ver' => $template['minor_ver'] ?? null, 'format' => $template['format'] ?? null,
            'representation' => $template['representation'] ?? null,
        ], fn ($value) => $value !== null);

        return $stored;
    }

    public static function latest(array $stored, ?string $defaultBioType = null): array
    {
        $result = [];
        foreach ($stored as $key => $template) {
            if (! is_array($template) || ! filled($template['tmp'] ?? null)) {
                continue;
            }
            $template['slot'] = (string) ($template['slot'] ?? $key);
            $type = (string) ($template['bio_type'] ?? $defaultBioType ?? '');
            if (! filled($template['major_ver'] ?? null) && ($type === '1' || ($template['type'] ?? '') === 'finger' || $defaultBioType === '1')) {
                $template['major_ver'] = self::inferMajorVersion('finger', (string) $template['tmp']);
            }
            $identity = implode(':', [$type, $template['slot'], $template['index'] ?? '0']);
            if (! isset($result[$identity]) || strcmp($template['observed_at'] ?? '', $result[$identity]['observed_at'] ?? '') >= 0) {
                $result[$identity] = $template;
            }
        }

        return array_values($result);
    }
}
