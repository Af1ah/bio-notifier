<?php

namespace App\Jobs;

use App\Models\AttendanceLog;
use App\Models\FrappeSetting;
use App\Models\Organisation;
use App\Services\Integrations\FrappeCheckins;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

class PushFrappeCheckins implements ShouldQueue, ShouldBeUnique
{
    use Queueable;

    public int $uniqueFor = 300;

    public int $timeout = 100;

    public int $tries = 1;

    public function __construct(public string $tenantId) {}

    public function uniqueId(): string
    {
        return $this->tenantId;
    }

    public function handle(FrappeCheckins $frappe): void
    {
        Organisation::findOrFail($this->tenantId)->run(function () use ($frappe) {
            Cache::lock(FrappeCheckins::LOCK, 115)->get(function () use ($frappe) {
                $settings = FrappeSetting::find(1);
                if (! $settings?->enabled || $settings->cooldown_until?->isFuture()) {
                    return;
                }

                $deliveries = DB::table('frappe_checkin_deliveries');
                $due = (clone $deliveries)->where('retry_at', '<=', now())->orderBy('retry_at')
                    ->orderBy('attendance_log_id')->limit(10)->get();
                if ($due->isEmpty()) {
                    return;
                }

                $address = $frappe->resolvePublicAddress($settings->hostname);
                $punches = AttendanceLog::findMany($due->pluck('attendance_log_id'))->keyBy('id');

                foreach ($due as $delivery) {
                    $punch = $punches->get($delivery->attendance_log_id);
                    if (! $punch) {
                        continue;
                    }

                    $result = $frappe->push($settings, $punch, $address);
                    $attempts = $delivery->attempts + 1;
                    $clock = now();
                    $retryAt = $result->retryable() && $attempts < 6
                        ? $clock->copy()->addSeconds(max($result->cooldownSeconds, 60 * 2 ** ($attempts - 1)))
                        : null;

                    (clone $deliveries)->where('attendance_log_id', $delivery->attendance_log_id)->update([
                        'attempts' => $attempts,
                        'last_attempt_at' => $clock,
                        'sent_at' => $result->outcome === null ? null : $clock,
                        'outcome' => $result->outcome,
                        'error' => $result->error,
                        'retry_at' => $retryAt,
                    ]);

                    if ($result->cooldownSeconds > 0) {
                        $settings->update(['cooldown_until' => $clock->copy()->addSeconds($result->cooldownSeconds)]);

                        break;
                    }
                }
            });
        });
    }
}
