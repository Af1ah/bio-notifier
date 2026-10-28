<?php

namespace App\Services\Support;

use Illuminate\Support\Carbon;

final class TenantTimezone
{
    public static function parse(mixed $value): Carbon
    {
        return Carbon::parse($value, self::get());
    }

    public static function formatInstant(mixed $value, string $format): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }

        return Carbon::parse($value, config('app.timezone'))->setTimezone(self::get())->format($format);
    }

    public static function get(): string
    {
        $zone = tenant()?->timezone ?: config('attendance.timezone', 'Asia/Kolkata');

        return in_array($zone, \DateTimeZone::listIdentifiers(\DateTimeZone::ALL_WITH_BC), true) ? $zone : 'Asia/Kolkata';
    }
}
