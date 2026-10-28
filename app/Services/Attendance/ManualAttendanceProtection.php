<?php

namespace App\Services\Attendance;

use App\Models\AttendanceDay;

final class ManualAttendanceProtection
{
    public function applies(AttendanceDay $day): bool
    {
        return in_array($day->source, ['manual', 'mixed'], true) || $day->approvals()->where('type', 'attendance')
            ->where(function ($q) {
                $q->where(fn ($manual) => $manual->whereIn('state', ['pending', 'approved'])->whereIn('proposal->reason', ['manual_correction', 'manual_attendance']))
                    ->orWhere(fn ($checkout) => $checkout->where('state', 'approved')->where('proposal->reason', 'missing_checkout'));
            })->exists();
    }
}
