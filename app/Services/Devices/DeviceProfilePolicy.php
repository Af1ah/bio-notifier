<?php

namespace App\Services\Devices;

use App\Models\Device;

final class DeviceProfilePolicy
{
    public static function name(Device $device): string
    {
        $info = $device->options['observed_metadata'] ?? [];
        $model = strtolower($info['model'] ?? $device->model ?? '');
        $platform = strtolower($info['platform'] ?? '');
        if (($info['biometric_type'] ?? null) === '9' || (str_contains($model, 'x 2008') && str_contains($platform, 'zmm510'))) {
            return 'visible-face-ta';
        }
        // K90 reports generic x 2008 with ZLM60. F22 reports ZMM220. Other standard
        // ZKTeco standalone terminals (F18/K40/IN01 etc.) report fp_algorithm_version 10
        // without visible-light face AI.
        if (
            str_contains($model, 'f22') || str_contains($model, 'f18') || str_contains($model, 'f19') || str_contains($model, 'f21')
            || str_contains($model, 'k14') || str_contains($model, 'k20') || str_contains($model, 'k30') || str_contains($model, 'k40') || str_contains($model, 'k50') || str_contains($model, 'k90')
            || str_contains($model, 'in01') || str_contains($model, 'tx628')
            || str_contains($platform, 'zmm220') || str_contains($platform, 'zlm60') || str_contains($platform, 'zem560') || str_contains($platform, 'zem600')
            || (str_contains($model, 'x 2008') && str_contains($platform, 'zlm60'))
            || (filled($info['fp_algorithm_version'] ?? null) && ($info['biometric_type'] ?? null) !== '9' && empty($info['max_face_count']) && empty($info['face_count']))
        ) {
            return 'legacy-fp';
        }
        if (str_contains($model, 'iface') || str_contains($model, 'uface')) {
            return 'legacy-face';
        }

        return 'unknown';
    }

    public static function supportsValidity(Device $device): bool
    {
        $info = $device->options['observed_metadata'] ?? [];

        return strtolower($info['model'] ?? '') === 'x 2008'
            && strtolower($info['platform'] ?? '') === 'zmm510_tft'
            && ($info['firmware_version'] ?? '') === 'ZMM510-NF24VB-Ver2.2.1';
    }

    public static function allows(Device $device, string $operation): bool
    {
        if ($operation === 'set_user_validity') {
            return self::supportsValidity($device);
        }

        // USERINFO carries the basic employee record and optional RFID card,
        // not a biometric template. A registered, synchronised binding is
        // sufficient for this operation. Keep all biometric operations below
        // profile-gated until a compatible terminal family is known.
        if (in_array($operation, ['info', 'check', 'query_users', 'get_option', 'upsert_user'], true)) {
            return true;
        }
        if (self::name($device) === 'unknown') {
            return false;
        }

        return $operation !== 'update_biophoto' || self::name($device) === 'visible-face-ta';
    }

    public static function format(Device $device, string $format): bool
    {
        return match ($format) {
            // RFID travels with USERINFO rather than a biometric template.
            // Fingerprint and face formats remain profile-gated.
            'rfid' => true,
            'finger' => self::name($device) !== 'unknown',
            'face' => self::name($device) === 'legacy-face',
            'ai_face','ai_face_template' => self::name($device) === 'visible-face-ta',default => false
        };
    }
}
