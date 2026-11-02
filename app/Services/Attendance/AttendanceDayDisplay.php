<?php

namespace App\Services\Attendance;

use App\Models\AttendanceDay;

final class AttendanceDayDisplay
{
    public static function forDay(?AttendanceDay $day): array
    {
        $status = $day?->status ?? 'not_calculated';
        $unapprovedCheckout = false;
        if ($day) {
            $day->loadMissing('approvals');
            $current = $day->approvals->filter(fn ($approval) => $approval->type === 'attendance'
                && (int) $approval->calculation_revision === (int) $day->calculation_revision);
            $unapprovedCheckout = $current->contains(fn ($request) => in_array($request->state, ['pending', 'rejected'], true)
                && ($request->proposal['reason'] ?? null) === 'missing_checkout'
                && ! $current->contains(fn ($decision) => $decision->state === 'approved'
                    && $decision->attendance_occurrence_id === $request->attendance_occurrence_id
                    && (($decision->proposal['reason'] ?? null) === 'missing_checkout'
                        || (($decision->proposal['reason'] ?? null) === 'manual_correction' && filled($decision->proposal['proposed_out_at'] ?? null)))));
        }
        if ($unapprovedCheckout) {
            $status = 'absent';
        }
        $minutes = $unapprovedCheckout ? 0 : (int) ($day?->approved_worked_minutes ?: $day?->candidate_worked_minutes);

        return ['status' => $status, 'minutes' => $minutes, 'unapproved_checkout' => $unapprovedCheckout,
            'label' => match ($status) {
                'present' => 'Present', 'half_day' => 'Half day', 'absent' => 'Absent',
                'off', 'holiday' => 'Off', 'no_shift' => 'No shift', 'pending', 'pending_approval' => 'Needs review', default => 'Not calculated',
            },
            'color' => match ($status) {
                'present' => 'success', 'half_day' => 'warning', 'absent' => 'danger', default => 'gray',
            }] + self::hours($minutes);
    }

    public static function hours(int $minutes): array
    {
        return ['clock' => sprintf('%02d:%02d', intdiv($minutes, 60), $minutes % 60)];
    }
}
