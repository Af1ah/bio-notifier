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
        $actor = $this->authorizeActor($actor, (int) ($data['user_id'] ?? 0));
        $selection = Validator::make($data, ['user_id' => 'required|integer|exists:users,id', 'work_date' => 'required|date_format:Y-m-d'.($actor->hasManagementAccess() ? '' : '|before_or_equal:'.today(TenantTimezone::get())->toDateString()), 'entry_mode' => 'required|in:manual,correction',
            'in_time' => 'required|date_format:H:i', 'out_time' => 'required|date_format:H:i', 'reason' => 'required|string|max:2000'])->validate();

        return DB::transaction(function () use ($actor, $selection) {
            $user = User::employees()->lockForUpdate()->findOrFail($selection['user_id']);
            $day = AttendanceDay::where('user_id', $user->id)->whereDate('work_date', $selection['work_date'])->first();
            if (($day ? 'correction' : 'manual') !== $selection['entry_mode']) {
                throw ValidationException::withMessages(['work_date' => 'Attendance changed while this form was open. Select the date again before submitting.']);
            }

            $match = app(AttendanceEntryContext::class)->match($user, $selection['work_date'], $selection['in_time'], $selection['out_time']);
            if (! $match['occurrence'] && $day && (app(ManualAttendanceProtection::class)->applies($day) || $day->approvals()->where('state', 'approved')->exists())) {
                throw ValidationException::withMessages(['work_date' => 'This date has an approved decision without a shift record. Review its attendance baseline first.']);
            }
            // Existing punches need a calculated baseline so edits replace times through approval, rather than extending them.
            if (! $match['occurrence'] && $user->attendanceLogs()->whereBetween('punched_at', [$match['start'], $match['end']])->exists()) {
                $day = app(AttendanceCalculationService::class)->calculate($user, TenantTimezone::parse($selection['work_date']));
                $match = app(AttendanceEntryContext::class)->match($user, $selection['work_date'], $selection['in_time'], $selection['out_time']);
            }
            if ($match['occurrence']) {
                return $this->correct($day, $actor, ['attendance_occurrence_id' => $match['occurrence']->id,
                    'proposed_in_at' => $match['in']->toDateTimeString(), 'proposed_out_at' => $match['out']->toDateTimeString(), 'reason' => $selection['reason']]);
            }

            return $this->add($actor, $selection + ['schedule_id' => $match['schedule_id']]);
        });
    }

    public function add(User $actor, array $data): AttendanceDay
    {
        $actor = $this->authorizeActor($actor, (int) ($data['user_id'] ?? 0));
        // Retain the service contract for imports and existing integrations while the UI submits one work date plus two times.
        $data['in_time'] ??= isset($data['in_at']) ? TenantTimezone::parse($data['in_at'])->format('H:i') : null;
        $data['out_time'] ??= isset($data['out_at']) ? TenantTimezone::parse($data['out_at'])->format('H:i') : null;
        $data = Validator::make($data, ['user_id' => 'required|integer|exists:users,id', 'schedule_id' => 'required|integer|exists:schedules,id', 'work_date' => 'required|date'.($actor->hasManagementAccess() ? '' : '|before_or_equal:'.today(TenantTimezone::get())->toDateString()), 'in_time' => 'required|date_format:H:i', 'out_time' => 'required|date_format:H:i', 'reason' => 'required|string|max:2000'])->validate();

        return DB::transaction(function () use ($actor, $data) {
            $user = User::employees()->lockForUpdate()->findOrFail($data['user_id']);
            $date = TenantTimezone::parse($data['work_date'])->startOfDay();
            $shift = Schedule::where('status', true)->findOrFail($data['schedule_id']);
            if (! $actor->hasManagementAccess()) {
                $match = app(AttendanceEntryContext::class)->match($user, $date->toDateString(), $data['in_time'], $data['out_time']);
                abort_unless((int) $match['schedule_id'] === $shift->id, 403);
            }
            $rule = $shift->activeRuleRevision($date);
            if (! $rule) {
                throw ValidationException::withMessages(['schedule_id' => 'This shift has no rule revision for the selected date.']);
            }
            $in = TenantTimezone::parse($date->toDateString().' '.$data['in_time']);
            $out = TenantTimezone::parse($date->toDateString().' '.$data['out_time']);
            $overnight = (int) $rule->end_day_offset > 0 || $rule->end_time <= $rule->start_time;
            $start = TenantTimezone::parse($date->toDateString().' '.$rule->start_time)->subMinutes($rule->earliest_arrival_minutes);
            if ($overnight && $in->lt($start)) {
                $in->addDay();
            }
            if ($out->lte($in) && $overnight) {
                $out->addDay();
            }
            if ($out->lte($in)) {
                throw ValidationException::withMessages(['out_time' => 'Checkout must be after check-in. Choose a night shift when checkout is on the next day.']);
            }
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
        $actor = $this->authorizeActor($actor, (int) $day->user_id);
        if (! $actor->hasManagementAccess()) {
            abort_if(filled($data['proposed_status'] ?? null) || $day->work_date->toDateString() > today(TenantTimezone::get())->toDateString(), 403);
        }
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

    private function authorizeActor(User $actor, int $userId): User
    {
        $actor = $actor->fresh();
        abort_unless($actor && ($actor->hasManagementAccess() || ($actor->id === $userId && $actor->canAccessEmployeePortal() && ! $actor->requires_password_change)), 403);

        return $actor;
    }
}
