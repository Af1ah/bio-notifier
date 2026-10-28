<?php

namespace App\Jobs;

use App\Models\Organisation;
use App\Models\User;
use App\Services\Attendance\AttendanceCalculationService;
use Carbon\Carbon;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

class RefreshAttendanceDay implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public int $timeout = 60;

    public function __construct(public string $tenantId, public int $userId, public string $workDate) {}

    public function signalId(): string
    {
        return 'refresh:'.$this->tenantId.':'.$this->userId.':'.$this->workDate;
    }

    public function handle(AttendanceCalculationService $calculator): void
    {
        $tenant = Organisation::find($this->tenantId);
        if (! $tenant) {
            return;
        }
        $previous = tenant();
        tenancy()->initialize($tenant);
        try {
            $user = User::employees()->find($this->userId);
            if ($user) {
                $calculator->calculate($user, Carbon::parse($this->workDate));
            }
        } finally {
            tenancy()->end();
            if ($previous) {
                tenancy()->initialize($previous);
            }
        }
    }
}
