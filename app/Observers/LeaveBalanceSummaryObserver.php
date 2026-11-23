<?php

namespace App\Observers;

use App\Models\AttendanceLog;
use App\Models\AttendanceOccurrence;
use App\Models\LeaveCreditLot;
use App\Models\LeaveLedgerEntry;
use App\Models\User;
use App\Services\Leave\LeaveBalanceSummaryService;
use Illuminate\Database\Eloquent\Model;

final class LeaveBalanceSummaryObserver
{
    public function saved(Model $model): void
    {
        $userId = match (true) {
            $model instanceof User => $model->id,
            $model instanceof AttendanceLog => $model->user?->id,
            $model instanceof AttendanceOccurrence => $model->attendanceDay?->user_id,
            $model instanceof LeaveLedgerEntry => LeaveCreditLot::find($model->leave_credit_lot_id)?->user_id,
            default => $model->getAttribute('user_id'),
        };
        LeaveBalanceSummaryService::invalidate($userId === null ? null : (int) $userId);
    }

    public function deleted(Model $model): void
    {
        $this->saved($model);
    }
}
