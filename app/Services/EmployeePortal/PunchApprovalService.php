<?php

namespace App\Services\EmployeePortal;

use App\Models\AttendanceLog;
use App\Models\PortalPunch;
use App\Models\User;
use App\Services\Devices\PrivateBiometricPhoto;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final class PunchApprovalService
{
    public function decide(PortalPunch $request, User $actor, bool $approve, ?string $note = null): PortalPunch
    {
        $actor = $actor->fresh();
        abort_unless($actor?->is_enabled && $actor->hasManagementAccess(), 403);
        abort_if($actor->id === $request->user_id, 403);
        if (mb_strlen((string) $note) > 1000 || (! $approve && blank($note))) {
            throw ValidationException::withMessages(['review_note' => 'Add a reason of up to 1,000 characters.']);
        }
        try {
            return DB::transaction(function () use ($request, $actor, $approve, $note) {
                $user = User::lockForUpdate()->findOrFail($request->user_id);
                $request = PortalPunch::where('user_id', $user->id)->lockForUpdate()->findOrFail($request->id);
                $state = $approve ? 'approved' : 'rejected';
                if ($request->state !== 'pending') {
                    if ($request->state === $state) {
                        return $request;
                    }
                    throw ValidationException::withMessages(['review_note' => 'This request has already been reviewed.']);
                }
                $log = null;
                if ($approve) {
                    if ($user->pin !== ($request->evidence['pin'] ?? null)) {
                        throw ValidationException::withMessages(['review_note' => 'The employee PIN changed. Review their attendance before approving this request.']);
                    }
                    try {
                        PrivateBiometricPhoto::read($request->photo_path);
                    } catch (\RuntimeException $exception) {
                        throw ValidationException::withMessages(['review_note' => 'The photo is unavailable. This punch cannot be approved.']);
                    }
                    $log = AttendanceLog::create([
                        'device_id' => null, 'portal_user_id' => $user->id, 'portal_request_id' => $request->request_id,
                        'pin' => $request->evidence['pin'], 'punched_at' => $request->punched_at,
                        'status' => $request->kind === 'in' ? 0 : 1, 'verify_type' => 254,
                        'raw_data' => $request->evidence + ['punch_request_id' => $request->id, 'approved_by_user_id' => $actor->id],
                    ]);
                }
                $request->update(['state' => $state, 'reviewed_by_user_id' => $actor->id, 'reviewed_at' => now(),
                    'review_note' => $note, 'attendance_log_id' => $log?->id]);

                return $request->fresh();
            });
        } catch (UniqueConstraintViolationException $exception) {
            throw ValidationException::withMessages(['review_note' => 'A punch already exists at this time. Review the attendance record before deciding.']);
        }
    }
}
