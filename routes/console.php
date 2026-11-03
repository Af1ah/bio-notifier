<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

Schedule::command('attendance:recalculate-nightly --due')->everyMinute()->withoutOverlapping(10);

Schedule::command('attendance:recover-runs')->everyMinute()->withoutOverlapping(10);

Schedule::command('ebio:replay-receipts')->everyMinute()->withoutOverlapping();

Schedule::command('users:prune-imports')->hourly()->withoutOverlapping();

Schedule::command('gateway:recover')->everyMinute()->withoutOverlapping();

Schedule::command('leave:sync-balances')->hourly()->withoutOverlapping();
