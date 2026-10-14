<?php

namespace App\Services\Attendance;

use App\Models\AttendanceDay;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class AttendanceBaselineService
{
    public function evidence(AttendanceDay $day): array
    {
        $user = $day->user;
        $set = app(ShiftAssignmentResolver::class)->resolve($user, $day->work_date);

        return ['signature' => app(AttendanceCalculationService::class)->inputSignature($user, $day->work_date, $set),
            'assignment' => $set?->load('slots.schedule')->toArray(),
            'punches' => $user->attendanceLogs()->whereBetween('punched_at', [$day->work_date->copy()->subDay(), $day->work_date->copy()->addDays(2)])->orderBy('punched_at')->get()->toArray()];
    }

    public function adopt(AttendanceDay $day, User $actor, string $reason, string $signature, bool $confirmed): void
    {
        abort_unless($actor->privilege === 14, 403);
        if (! $confirmed || trim($reason) === '') {
            throw ValidationException::withMessages(['reason' => 'Confirm the reviewed evidence and record why the original approval is valid.']);
        }
        DB::transaction(function () use ($day, $actor, $reason, $signature) {
            User::lockForUpdate()->findOrFail($day->user_id);
            $day = AttendanceDay::lockForUpdate()->findOrFail($day->id);
            if (! empty($day->explanation['input_signature']) || ! $day->approvals()->where('state', 'approved')->exists()) {
                throw ValidationException::withMessages(['attendance' => 'Only an approved legacy day without a baseline can be adopted.']);
            }
            $evidence = $this->evidence($day);
            if (! hash_equals($evidence['signature'], $signature)) {
                throw ValidationException::withMessages(['attendance' => 'The evidence changed while reviewing. Reload and review it again.']);
            }
            DB::table('attendance_baseline_adoptions')->insert(['attendance_day_id' => $day->id, 'calculation_revision' => $day->calculation_revision,
                'reviewed_input_signature' => $signature, 'original_snapshot' => json_encode(['day' => $day->toArray(), 'occurrences' => $day->occurrences()->get()->toArray(), 'approvals' => $day->approvals()->get()->toArray()], JSON_THROW_ON_ERROR),
                'reviewed_evidence' => json_encode($evidence, JSON_THROW_ON_ERROR), 'reason' => $reason, 'reviewed_by_user_id' => $actor->id, 'reviewed_at' => now()]);
            $day->update(['explanation' => array_merge($day->explanation ?? [], ['input_signature' => $signature, 'baseline_source' => 'explicit_hr_review', 'baseline_reviewed_at' => now()->toIso8601String()])]);
        });
    }
}
