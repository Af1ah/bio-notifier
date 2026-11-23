<?php

namespace App\Console\Commands;

use App\Services\Attendance\AttendanceRunQueue;
use Illuminate\Console\Command;

class RecoverAttendanceRuns extends Command
{
    protected $signature = 'attendance:recover-runs';

    protected $description = 'Restore the queue signal for unfinished serial attendance work';

    public function handle(AttendanceRunQueue $queue): int
    {
        $queue->dispatchNext();

        return self::SUCCESS;
    }
}
