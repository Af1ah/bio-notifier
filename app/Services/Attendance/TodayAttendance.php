<?php

namespace App\Services\Attendance;

use App\Models\AttendanceLog;
use App\Models\User;
use App\Services\Support\TenantTimezone;
use Illuminate\Database\Eloquent\Builder;

final class TodayAttendance
{
    public static function employees(): Builder
    {
        return User::employees()->where('is_enabled', true);
    }

    public static function logs(): Builder
    {
        return self::withinToday(AttendanceLog::query())
            ->whereHas('user', fn (Builder $query) => $query->employees()->where('is_enabled', true));
    }

    public static function filterEmployees(Builder $query, bool $present): Builder
    {
        $query->where('is_enabled', true);

        return $present
            ? $query->whereHas('attendanceLogs', fn (Builder $logs) => self::withinToday($logs))
            : $query->whereDoesntHave('attendanceLogs', fn (Builder $logs) => self::withinToday($logs));
    }

    private static function withinToday(Builder $query): Builder
    {
        $now = now(TenantTimezone::get());

        // Punch timestamps are stored as tenant-local wall time, not UTC instants.
        return $query->where('punched_at', '>=', $now->copy()->startOfDay()->format('Y-m-d H:i:s'))
            ->where('punched_at', '<=', $now->format('Y-m-d H:i:s'));
    }
}
