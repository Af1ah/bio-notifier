<?php

namespace App\Services\Attendance;

use App\Models\AttendanceDay;
use App\Models\AttendanceLog;
use App\Models\Schedule;
use App\Models\User;
use App\Services\Support\TenantTimezone;
use Illuminate\Support\Facades\Validator;

final class AttendancePunchEvidence
{
    public function read(mixed $userId, mixed $date, ?AttendanceDay $day = null, mixed $scheduleId = null): array
    {
        abort_unless((int) auth()->user()?->privilege === 14, 403);
        if (Validator::make(['user_id' => $userId, 'date' => $date], ['user_id' => 'required|integer|min:1', 'date' => 'required|date_format:Y-m-d'])->fails()) {
            return ['logs' => collect(), 'truncated' => false];
        }
        $user = User::employees()->find($userId);
        if (! $user) {
            return ['logs' => collect(), 'truncated' => false];
        }
        $windows = $day?->occurrences()->get(['window_starts_at', 'window_ends_at'])->map(fn ($o) => [$o->window_starts_at, $o->window_ends_at])->all() ?? [];
        if (! $windows && filter_var($scheduleId, FILTER_VALIDATE_INT)) {
            $rule = Schedule::find($scheduleId)?->activeRuleRevision(TenantTimezone::parse($date));
            if ($rule) {
                $overnight = (int) $rule->end_day_offset > 0 || $rule->end_time <= $rule->start_time;
                $windows[] = [TenantTimezone::parse($date.' '.$rule->start_time)->subMinutes($rule->earliest_arrival_minutes),
                    TenantTimezone::parse($date.' '.$rule->end_time)->addDays($overnight ? 1 : 0)->addMinutes($rule->checkout_cutoff_minutes)];
            }
        }
        $logs = AttendanceLog::with('device:id,name')->where('pin', $user->pin)
            ->where(function ($query) use ($date, $windows) {
                $query->whereDate('punched_at', $date);
                foreach ($windows as [$start, $end]) {
                    $query->orWhereBetween('punched_at', [$start->format('Y-m-d H:i:s'), $end->format('Y-m-d H:i:s')]);
                }
            })->orderBy('punched_at')->orderBy('id')->limit(101)->get();

        return ['logs' => $logs->take(100), 'truncated' => $logs->count() > 100];
    }
}
