<?php

namespace App\Services\Leave;

use App\Models\User;
use App\Services\Attendance\AttendanceCalculationService;
use App\Services\Attendance\ShiftAssignmentResolver;
use Carbon\Carbon;
use Carbon\CarbonPeriod;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

class LeaveRangeService
{
    private function validate(array $data, User $actor): array
    {
        abort_unless((int) $actor->privilege === 14, 403);
        $v = Validator::make($data, ['user_id' => 'required|integer|exists:users,id', 'leave_type_id' => 'required|integer|exists:leave_types,id', 'starts_on' => 'required|date_format:Y-m-d', 'ends_on' => 'required|date_format:Y-m-d|after_or_equal:starts_on', 'duration' => 'required|in:full,half', 'state' => 'required|in:pending,approved', 'notes' => 'nullable|string|max:1000'])->validate();
        if (Carbon::parse($v['starts_on'])->diffInDays(Carbon::parse($v['ends_on'])) > 92) {
            throw ValidationException::withMessages(['ends_on' => 'Choose up to 93 calendar days per request.']);
        }

        return $v;
    }

    public function preview(array $data, User $actor): array
    {
        $v = $this->validate($data, $actor);
        DB::beginTransaction();
        try {
            $result = $this->apply($v, $actor);

            return $result + ['signature' => hash('sha256', json_encode([$v, $result['rows']], JSON_THROW_ON_ERROR))];
        } finally {
            DB::rollBack();
        }
    }

    public function submit(array $data, string $requestKey, string $expectedSignature, User $actor): array
    {
        $v = $this->validate($data, $actor);
        Validator::make(['request_key' => $requestKey], ['request_key' => 'required|uuid'])->validate();
        $payload = hash('sha256', json_encode($v, JSON_THROW_ON_ERROR));

        return DB::transaction(function () use ($v, $requestKey, $expectedSignature, $actor, $payload) {
            User::employees()->lockForUpdate()->findOrFail($v['user_id']);
            $old = DB::table('leave_range_requests')->where('request_key', $requestKey)->first();
            if ($old) {
                if ($old->payload_hash !== $payload || (int) $old->created_by_user_id !== $actor->id) {
                    throw ValidationException::withMessages(['starts_on' => 'This request key has already been used for another request.']);
                }

                return json_decode($old->result, true, 512, JSON_THROW_ON_ERROR);
            }
            $result = $this->apply($v, $actor);
            if (! hash_equals($expectedSignature, hash('sha256', json_encode([$v, $result['rows']], JSON_THROW_ON_ERROR)))) {
                throw ValidationException::withMessages(['starts_on' => 'Leave coverage changed. Preview the request again before submitting.']);
            }
            DB::table('leave_range_requests')->insert(['request_key' => $requestKey, 'payload_hash' => $payload, 'created_by_user_id' => $actor->id, 'user_id' => $v['user_id'], 'result' => json_encode($result, JSON_THROW_ON_ERROR), 'created_at' => now(), 'updated_at' => now()]);

            return $result;
        }, 3);
    }

    private function apply(array $v, User $actor): array
    {
        $user = User::employees()->lockForUpdate()->findOrFail($v['user_id']);
        $rows = [];
        $ids = [];
        foreach (CarbonPeriod::create($v['starts_on'], $v['ends_on']) as $date) {
            $set = app(ShiftAssignmentResolver::class)->resolve($user, $date);
            $off = $set && ! $set->slots->contains(fn ($slot) => $slot->schedule?->activeRuleRevision($date)?->weekdays()->where('weekday', $date->dayOfWeek)->exists());
            if ($off || app(AttendanceCalculationService::class)->isHoliday($user, $date)) {
                $rows[] = ['date' => $date->toDateString(), 'kind' => $off ? 'off' : 'holiday', 'paid_half_units' => 0, 'unpaid_half_units' => 0];

                continue;
            }
            $leave = app(EmployeeLeaveService::class)->save(null, ['user_id' => $user->id, 'leave_type_id' => $v['leave_type_id'], 'leave_date' => $date->toDateString(), 'duration' => $v['duration'], 'state' => $v['state'], 'notes' => $v['notes'] ?? null], $actor);
            $paid = app(LeaveBalanceService::class)->payrollCoverage($leave) ?? 0;
            $rows[] = ['date' => $date->toDateString(), 'kind' => 'leave', 'paid_half_units' => $paid, 'unpaid_half_units' => $leave->half_units - $paid];
            $ids[] = $leave->id;
        }
        if (! $ids) {
            throw ValidationException::withMessages(['starts_on' => 'This range contains only holidays or rostered off days.']);
        }

        return ['rows' => $rows, 'leave_ids' => $ids];
    }
}
