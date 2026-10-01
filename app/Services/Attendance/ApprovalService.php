<?php

namespace App\Services\Attendance;

use App\Models\AttendanceApproval;
use App\Models\AttendanceCorrection;
use App\Models\AttendanceDay;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class ApprovalService
{
    public function decide(AttendanceApproval $approval, User $actor, bool $approve, ?string $reason = null, ?string $checkoutAt = null): void
    {
        DB::transaction(function () use ($approval, $actor, $approve, $reason, $checkoutAt) {
            $item = AttendanceApproval::lockForUpdate()->findOrFail($approval->id);
            if ($item->state !== 'pending') {
                throw ValidationException::withMessages(['approval' => 'This item is no longer pending.']);
            }
            if (! $approve && blank($reason)) {
                throw ValidationException::withMessages(['reason' => 'A rejection reason is required.']);
            }
            $day = AttendanceDay::lockForUpdate()->findOrFail($item->attendance_day_id);
            if ($day->calculation_revision !== $item->calculation_revision) {
                throw ValidationException::withMessages(['approval' => 'The calculation changed. Review the new revision.']);
            }
            if ($approve && $item->type === 'overtime' && $day->approvals()->where('type', 'attendance')->where('state', 'pending')->exists()) {
                throw ValidationException::withMessages(['approval' => 'Approve attendance evidence before overtime.']);
            }
            if ($approve && $item->type === 'attendance') {
                $occurrence = $item->occurrence()->lockForUpdate()->first();
                if ($checkoutAt !== null) {
                    if (($item->proposal['reason'] ?? null) !== 'missing_checkout' || ! $occurrence) {
                        throw ValidationException::withMessages(['checkout_at' => 'Checkout can only be edited for a missing-checkout approval.']);
                    }
                    if ($day->approvals()->where('type', 'overtime')->where('state', 'approved')->exists()) {
                        throw ValidationException::withMessages(['checkout_at' => 'Overtime is already approved. Resolve that approval before changing checkout.']);
                    }
                    \Illuminate\Support\Facades\Validator::make(['checkout_at' => $checkoutAt], ['checkout_at' => ['required', 'date']])->validate();
                    $checkout = Carbon::parse($checkoutAt);
                    if (! $occurrence->first_in_at || $checkout->lte($occurrence->first_in_at) || $checkout->gt($occurrence->window_ends_at)) {
                        throw ValidationException::withMessages(['checkout_at' => 'Checkout must be after check-in and within the shift attendance window.']);
                    }
                    $originalMinutes = $occurrence->candidate_worked_minutes;
                    $originalAssumed = $occurrence->assumed_out_at?->toIso8601String();
                    app(AttendanceCalculationService::class)->reviseAssumedCheckout($day, $occurrence, $checkout);
                    $item->proposal = array_merge($item->proposal, [
                        'original_assumed_out_at' => $originalAssumed,
                        'original_minutes' => $originalMinutes,
                        'approved_out_at' => $checkout->toIso8601String(),
                        'minutes' => $occurrence->candidate_worked_minutes,
                    ]);
                    $item->save();
                    $pendingOvertime = $day->approvals()->where('type', 'overtime')->where('state', 'pending')->get();
                    foreach ($pendingOvertime as $overtime) {
                        $overtime->update(['state' => 'superseded', 'superseded_at' => now()]);
                    }
                    if ($day->candidate_overtime_minutes > 0) {
                        $day->approvals()->create(['type' => 'overtime', 'state' => 'pending', 'calculation_revision' => $day->calculation_revision,
                            'proposal' => ['reason' => 'qualifying_overtime', 'minutes' => $day->candidate_overtime_minutes, 'basis' => $day->explanation['overtime_basis'] ?? 'after_required_hours']]);
                    }
                }
                if (($item->proposal['reason'] ?? null) === 'manual_correction') {
                    $correction = AttendanceCorrection::lockForUpdate()->findOrFail($item->proposal['correction_id']);
                    $occurrence = $occurrence ?: $day->occurrences()->lockForUpdate()->first();
                    if ($occurrence && $correction->proposed_in_at && $correction->proposed_out_at) {
                        $minutes = Carbon::parse($correction->proposed_in_at)->diffInMinutes($correction->proposed_out_at);
                        $occurrence->update(['first_in_at' => $correction->proposed_in_at, 'last_out_at' => $correction->proposed_out_at, 'assumed_out_at' => null, 'candidate_worked_minutes' => $minutes, 'approved_worked_minutes' => $minutes, 'status' => 'corrected']);
                    }
                    if ($correction->proposed_status) {
                        $day->status = $correction->proposed_status;
                        $day->save();
                    }
                    $correction->update(['state' => 'approved']);
                }
                if ($occurrence) {
                    $occurrence->update(['approved_worked_minutes' => $occurrence->candidate_worked_minutes]);
                    $day->update(['approved_worked_minutes' => $day->occurrences()->sum('approved_worked_minutes')]);
                }
            }
            if ($approve && $item->type === 'overtime') {
                $day->update(['approved_overtime_minutes' => $day->candidate_overtime_minutes]);
            }
            $item->update(['state' => $approve ? 'approved' : 'rejected', 'decided_by_user_id' => $actor->id, 'decision_reason' => $reason, 'decided_at' => now()]);
            if (! $approve && ($item->proposal['reason'] ?? null) === 'manual_correction') {
                AttendanceCorrection::whereKey($item->proposal['correction_id'])->update(['state' => 'rejected']);
            }
        });
    }
}
