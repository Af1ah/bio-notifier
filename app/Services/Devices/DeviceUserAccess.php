<?php

namespace App\Services\Devices;

use App\Models\DeviceAccessSetting;
use App\Models\DeviceRosterEntry;

/** Readback only: missing fields never imply unrestricted access. */
final class DeviceUserAccess
{
    public static function date(?string $value): string
    {
        if ($value === null || $value === '') {
            return 'Not reported';
        }
        if (preg_match('/^0+$/', $value)) {
            return 'Unset on device';
        }
        $date = str_replace('-', '', $value);
        if (preg_match('/^\d{8}$/', $date) && checkdate((int) substr($date, 4, 2), (int) substr($date, 6, 2), (int) substr($date, 0, 4))) {
            return substr($date, 0, 4).'-'.substr($date, 4, 2).'-'.substr($date, 6, 2);
        }

        return 'Unrecognized date';
    }

    public static function expiry(DeviceRosterEntry $record): string
    {
        $access = $record->access_settings ?? [];

        return ['0' => 'No expiration', '1' => 'Dates only', '2' => 'Entry limit only', '3' => 'Dates and entry limit'][$access['Expires'] ?? ''] ?? 'Not reported';
    }

    public static function entries(DeviceRosterEntry $record): string
    {
        $access = $record->access_settings ?? [];
        $values = array_values(array_unique(array_filter([
            $access['VaildCount'] ?? null, $access['ValidCount'] ?? null,
        ], fn ($value) => $value !== null && $value !== '')));
        if ($values === []) {
            return 'Not reported';
        }
        if (count($values) !== 1 || ! ctype_digit($values[0])) {
            return 'Unverified counter';
        }

        return $values[0].' (device-reported; unverified)';
    }

    public static function schedule(DeviceRosterEntry $record): string
    {
        $access = $record->access_settings ?? [];
        $tz = $access['TZ'] ?? '';
        if (! preg_match('/^[0-9a-fA-F]{16}$/', $tz)) {
            return 'Daily hours not reported';
        }
        $cacheKey = 'device-access-readback:'.tenant('id').':'.$record->device_id;
        if (! request()->attributes->has($cacheKey)) {
            request()->attributes->set($cacheKey, DeviceAccessSetting::where('device_id', $record->device_id)
                ->get()->keyBy(fn ($entry) => $entry->kind.':'.$entry->number));
        }
        $settings = request()->attributes->get($cacheKey);
        $mode = hexdec(substr($tz, 0, 4));
        if ($mode === 0) {
            $group = $settings->get('group:'.($access['Grp'] ?? ''));
            if (! $group?->reported) {
                return 'Group schedule not reported. Read access settings from this device.';
            }
            $slots = array_slice(explode(';', $group->reported['TZ']), 1);
        } elseif ($mode === 1) {
            $slots = array_map(fn ($offset) => (string) hexdec(substr($tz, $offset, 4)), [4, 8, 12]);
        } else {
            return 'Unrecognized period mode';
        }
        $schedules = [];
        foreach (array_unique(array_filter($slots, fn ($slot) => $slot !== '0')) as $slot) {
            $period = $settings->get('period:'.$slot);
            if (! $period?->reported) {
                $schedules[] = 'Period '.$slot.': hours not reported';

                continue;
            }
            $days = [];
            foreach (DeviceAccessSettings::DAYS as $day => $label) {
                $format = fn ($value) => substr($value, 0, 2).':'.substr($value, 2, 2);
                $days[] = $label.' '.$format($period->reported[$day.'Start']).'–'.$format($period->reported[$day.'End']);
            }
            $schedules[] = 'Period '.$slot.' (last read '.$period->reported_at?->toIso8601String().'): '.implode('; ', $days);
        }

        return $schedules ? implode(' | ', $schedules) : 'No daily hours reported; access behavior is unverified';
    }

    public static function periods(DeviceRosterEntry $record): string
    {
        $access = $record->access_settings ?? [];
        $tz = $access['TZ'] ?? '';
        if ($tz === '') {
            return 'Not reported';
        }
        if (! preg_match('/^[0-9a-fA-F]{16}$/', $tz)) {
            return 'Unrecognized period reference';
        }
        $mode = hexdec(substr($tz, 0, 4));
        if ($mode === 0) {
            return 'Group periods · group '.($access['Grp'] ?? 'not reported');
        }
        if ($mode !== 1) {
            return 'Unrecognized period mode';
        }
        $slots = [];
        foreach ([4, 8, 12] as $offset) {
            $slot = hexdec(substr($tz, $offset, 4));
            if ($slot !== 0) {
                $slots[] = $slot;
            }
        }

        return $slots ? 'Personal periods: '.implode(', ', $slots) : 'Personal periods: none reported';
    }
}
