<?php

namespace App\Services\Attendance;

use App\Models\AttendanceApproval;
use App\Models\AttendanceCorrection;
use App\Models\AttendanceDay;
use App\Models\ManualAttendanceEntry;
use App\Models\Schedule;
use App\Models\User;
use App\Services\Support\TenantTimezone;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

class AttendanceEntryService
{
    public function addOrCorrect(User $actor, array $data): AttendanceDay|AttendanceCorrection
    {
        abort_unless($actor->privilege === 14, 403);
        $selection = Validator::make($data, ['user_id' => 'required|integer|exists:users,id', 'work_date' => 'required|date_format:Y-m-d', 'entry_mode' => 'required|in:manual,correction'])->validate();

        return DB::transaction(function () use ($actor, $data, $selection) {
            $user = User::employees()->lockForUpdate()->findOrFail($selection['user_id']);
            $day = AttendanceDay::where('user_id', $user->id)->whereDate('work_date', $selection['work_date'])->first();
            if (($day ? 'correction' : 'manual') !== $selection['entry_mode']) {
                throw ValidationException::withMessages(['work_date' => 'Attendance changed while this form was open. Select the date again before submitting.']);
            }

            return $day ? $this->correct($day, $actor, $data) : $this->add($actor, $data);
        });
    }

    public function add(User $actor, array $data): AttendanceDay
    {
        abort_unless($actor->privilege === 14, 403);
        // Retain the service contract for imports and existing integrations while the UI submits one work date plus two times.
        $data['in_time'] ??= isset($data['in_at']) ? TenantTimezone::parse($data['in_at'])->format('H:i') : null;
        $data['out_time'] ??= isset($data['out_at']) ? TenantTimezone::parse($data['out_at'])->format('H:i') : null;
        $data = Validator::make($data, ['user_id' => 'required|integer|exists:users,id', 'schedule_id' => 'required|integer|exists:schedules,id', 'work_date' => 'required|date', 'in_time' => 'required|date_format:H:i', 'out_time' => 'required|date_format:H:i', 'reason' => 'required|string|max:2000'])->validate();

        return DB::transaction(function () use ($actor, $data) {
            $user = User::employees()->lockForUpdate()->findOrFail($data['user_id']);
            $date = TenantTimezone::parse($data['work_date'])->startOfDay();
            $shift = Schedule::where('status', true)->findOrFail($data['schedule_id']);
            $rule = $shift->activeRuleRevision($date);
            if (! $rule) {
                throw ValidationException::withMessages(['schedule_id' => 'This shift has no rule revision for the selected date.']);
            }
            $in = TenantTimezone::parse($date->toDateString().' '.$data['in_time']);
            $out = TenantTimezone::parse($date->toDateString().' '.$data['out_time']);
            $overnight = (int) $rule->end_day_offset > 0 || $rule->end_time <= $rule->start_time;
            if ($out->lte($in) && $overnight) {
                $out->addDay();
            }
            if ($out->lte($in)) {
                throw ValidationException::withMessages(['out_time' => 'Checkout must be after check-in. Choose a night shift when checkout is on the next day.']);
            }
            $start = TenantTimezone::parse($date->toDateString().' '.$rule->start_time)->subMinutes($rule->earliest_arrival_minutes);
            $end = TenantTimezone::parse($date->toDateString().' '.$rule->end_time)->addDays($overnight ? 1 : 0)->addMinutes($rule->checkout_cutoff_minutes);
            if ($in->lt($start) || $out->gt($end)) {
                throw ValidationException::withMessages(['in_time' => 'The time range must fit the selected '.($overnight ? 'night' : 'day').' shift window.']);
            }
            if (ManualAttendanceEntry::where('user_id', $user->id)->whereDate('work_date', $date)->where('schedule_id', '!=', $shift->id)->where('in_at', '<', $out)->where('out_at', '>', $in)->exists()) {
                throw ValidationException::withMessages(['schedule_id' => 'This interval overlaps a manual entry for another shift.']);
            }
            if (ManualAttendanceEntry::where('user_id', $user->id)->where('schedule_id', $shift->id)->where('in_at', $in->toDateTimeString())->where('out_at', $out->toDateTimeString())->exists()) {
                throw ValidationException::withMessages(['in_at' => 'An identical manual entry already exists. Use Correct to propose an edit.']);
            }
            ManualAttendanceEntry::create(collect($data)->except(['in_time', 'out_time'])->all() + ['in_at' => $in, 'out_at' => $out, 'shift_rule_revision_id' => $rule->id, 'created_by_user_id' => $actor->id]);

            return app(AttendanceCalculationService::class)->calculate($user, $date, preserveManualDecisions: false);
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
            if ($occ && ! empty($data['proposed_in_at']) && (TenantTimezone::parse($data['proposed_in_at'])->lt($occ->window_starts_at) || TenantTimezone::parse($data['proposed_out_at'])->gt($occ->window_ends_at))) {
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
