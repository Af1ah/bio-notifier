<?php

namespace App\Services\Support;

final class TenantTimezone
{
    public static function get(): string
    {
        $zone = tenant()?->timezone ?: config('attendance.timezone', 'Asia/Kolkata');

        return in_array($zone, \DateTimeZone::listIdentifiers(\DateTimeZone::ALL_WITH_BC), true) ? $zone : 'Asia/Kolkata';
    }
}
