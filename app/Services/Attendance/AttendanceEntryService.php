<?php

namespace App\Services\Attendance;

use App\Models\AttendanceApproval;
use App\Models\AttendanceCorrection;
use App\Models\AttendanceDay;
use App\Models\ManualAttendanceEntry;
use App\Models\Schedule;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

class AttendanceEntryService
{
    public function add(User $actor, array $data): AttendanceDay
    {
        abort_unless($actor->privilege === 14, 403);
        $data = Validator::make($data, ['user_id' => 'required|integer|exists:users,id', 'schedule_id' => 'required|integer|exists:schedules,id', 'work_date' => 'required|date', 'in_at' => 'required|date', 'out_at' => 'required|date|after:in_at', 'reason' => 'required|string|max:2000'])->validate();

        return DB::transaction(function () use ($actor, $data) {
            $user = User::employees()->lockForUpdate()->findOrFail($data['user_id']);
            $date = Carbon::parse($data['work_date'])->startOfDay();
            $shift = Schedule::where('status', true)->findOrFail($data['schedule_id']);
            $rule = $shift->activeRuleRevision($date);
            if (! $rule) {
                throw ValidationException::withMessages(['schedule_id' => 'This shift has no rule revision for the selected date.']);
            }
            $start = Carbon::parse($date->toDateString().' '.$rule->start_time)->subMinutes($rule->earliest_arrival_minutes);
            $end = Carbon::parse($date->toDateString().' '.$rule->end_time)->addDays($rule->end_day_offset)->addMinutes($rule->checkout_cutoff_minutes);
            if (Carbon::parse($data['in_at'])->lt($start) || Carbon::parse($data['out_at'])->gt($end)) {
                throw ValidationException::withMessages(['in_at' => 'Times must fit the selected shift attendance window, including overnight dates.']);
            }
            if (ManualAttendanceEntry::where('user_id', $user->id)->whereDate('work_date', $date)->where('schedule_id', '!=', $shift->id)->where('in_at', '<', $data['out_at'])->where('out_at', '>', $data['in_at'])->exists()) {
                throw ValidationException::withMessages(['schedule_id' => 'This interval overlaps a manual entry for another shift.']);
            }
            if (ManualAttendanceEntry::where('user_id', $user->id)->where('schedule_id', $shift->id)->where('in_at', Carbon::parse($data['in_at'])->toDateTimeString())->where('out_at', Carbon::parse($data['out_at'])->toDateTimeString())->exists()) {
                throw ValidationException::withMessages(['in_at' => 'An identical manual entry already exists. Use Correct to propose an edit.']);
            }
            ManualAttendanceEntry::create($data + ['shift_rule_revision_id' => $rule->id, 'created_by_user_id' => $actor->id]);

            return app(AttendanceCalculationService::class)->calculate($user, $date);
        });
    }

    public function correct(AttendanceDay $day, User $actor, array $data): AttendanceCorrection
    {
        abort_unless($actor->privilege === 14, 403);
        $data = Validator::make($data, ['attendance_occurrence_id' => 'nullable|integer', 'proposed_in_at' => 'nullable|date|required_with:proposed_out_at', 'proposed_out_at' => 'nullable|date|required_with:proposed_in_at|after:proposed_in_at', 'proposed_status' => 'nullable|in:present,half_day,absent,off,holiday', 'reason' => 'required|string|max:2000'])->validate();

        return DB::transaction(function () use ($day, $actor, $data) {
            $day = AttendanceDay::lockForUpdate()->findOrFail($day->id);
            $occ = filled($data['attendance_occurrence_id'] ?? null) ? $day->occurrences()->findOrFail($data['attendance_occurrence_id']) : null;
            if (! empty($data['proposed_in_at']) && ! $occ) {
                throw ValidationException::withMessages(['attendance_occurrence_id' => 'Select the shift occurrence being corrected.']);
            }
            if ($occ && ! empty($data['proposed_in_at']) && (Carbon::parse($data['proposed_in_at'])->lt($occ->window_starts_at) || Carbon::parse($data['proposed_out_at'])->gt($occ->window_ends_at))) {
                throw ValidationException::withMessages(['proposed_in_at' => 'Times must fit this row’s selected shift window.']);
            }
            if ($day->approvals()->where('type', 'attendance')->where('state', 'pending')->where('proposal->reason', 'manual_correction')->exists()) {
                throw ValidationException::withMessages(['reason' => 'Resolve the pending correction before submitting another.']);
            }
            $correction = AttendanceCorrection::create($data + ['attendance_day_id' => $day->id, 'created_by_user_id' => $actor->id, 'state' => 'pending']);
            AttendanceApproval::create(['attendance_day_id' => $day->id, 'attendance_occurrence_id' => $occ?->id, 'type' => 'attendance', 'state' => 'pending', 'calculation_revision' => $day->calculation_revision, 'requested_by_user_id' => $actor->id,
                'proposal' => ['reason' => 'manual_correction', 'correction_id' => $correction->id, 'original_status' => $day->status, 'original_occurrence' => $occ?->toArray()] + $data]);

            return $correction;
        });
    }
}
