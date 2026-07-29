<?php

namespace App\Events;

use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;
use App\Models\AttendanceLog;
use App\Models\Device;

class AttendanceReceived
{
    use Dispatchable;
    use SerializesModels;

    public function __construct(
        public AttendanceLog $attendanceLog,
        public Device $device
    ) {}
}
