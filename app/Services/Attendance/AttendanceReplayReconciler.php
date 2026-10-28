<?php

namespace App\Services\Attendance;

use App\Jobs\RefreshAttendanceDay;
use App\Models\AttendanceDay;
use App\Models\AttendanceLog;
use App\Models\User;

final class AttendanceReplayReconciler
{
    private array $checked = [];

    public function recoverMissingDay(AttendanceLog $log): void
    {
        if (! tenancy()->initialized) {
            return;
        }
        $date = $log->punched_at->toDateString();
        $key = tenant('id').':'.$log->pin.':'.$date;
        if (isset($this->checked[$key])) {
            return;
        }
        $this->checked[$key] = true;
        $user = User::employees()->where('pin', $log->pin)->first();
        if (! $user) {
            return;
        }
        // A replay of existing evidence must not disturb an existing day/overnight occurrence.
        $exists = AttendanceDay::where('user_id', $user->id)->where(fn ($q) => $q->whereDate('work_date', $date)
            ->orWhereHas('occurrences', fn ($occ) => $occ->where('window_starts_at', '<=', $log->punched_at)->where('window_ends_at', '>=', $log->punched_at)))->exists();
        if ($exists) {
            return;
        }
        foreach ([$log->punched_at->copy()->subDay()->toDateString(), $date] as $workDate) {
            RefreshAttendanceDay::dispatch((string) tenant('id'), $user->id, $workDate)->onConnection('gateway')->onQueue('attendance-live')->afterCommit();
        }
    }
}
