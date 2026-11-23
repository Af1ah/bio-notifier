<?php

namespace App\Console\Commands;

use App\Jobs\PushFrappeCheckins;
use App\Models\FrappeSetting;
use App\Models\Organisation;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Throwable;

class QueueFrappeCheckins extends Command
{
    protected $signature = 'frappe:queue-checkins';

    protected $description = 'Queue due Frappe HR check-in deliveries';

    public function handle(): int
    {
        $failed = false;
        foreach (Organisation::query()->lazyById() as $organisation) {
            try {
                $due = $organisation->run(fn () => Schema::hasColumn('frappe_settings', 'cooldown_until')
                    && FrappeSetting::whereKey(1)->where('enabled', true)
                        ->where(fn ($query) => $query->whereNull('cooldown_until')->orWhere('cooldown_until', '<=', now()))->exists()
                    && DB::table('frappe_checkin_deliveries')->where('retry_at', '<=', now())->exists());

                if ($due) {
                    PushFrappeCheckins::dispatch((string) $organisation->id)
                        ->onConnection('gateway')->onQueue('exports-notifications');
                }
            } catch (Throwable $exception) {
                report($exception);
                $failed = true;
            }
        }

        return $failed ? self::FAILURE : self::SUCCESS;
    }
}
