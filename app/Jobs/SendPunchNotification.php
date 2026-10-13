<?php

namespace App\Jobs;

use App\Models\AttendanceLog;
use App\Models\Organisation;
use App\Services\WhatsAppService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

class SendPunchNotification implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public int $timeout = 20;

    public function backoff(): array
    {
        return [30, 120];
    }

    public function __construct(public string $tenantId, public int $logId) {}

    public function handle(WhatsAppService $service): void
    {
        $tenant = Organisation::findOrFail($this->tenantId);
        $previous = tenant();
        tenancy()->initialize($tenant);
        try {
            $log = AttendanceLog::findOrFail($this->logId);
            $user = $log->user;
            if (! $user?->whatsapp_number || ! config('services.whatsapp.api_key') || ! config('services.whatsapp.instance_name')) {
                return;
            }
            if (! $service->sendMessage($user->whatsapp_number, "Hello {$user->name}, your attendance punch ({$log->status_label}) at {$log->punched_at->format('Y-m-d H:i:s')} has been recorded.")) {
                throw new \RuntimeException('Punch notification delivery failed.');
            }
        } finally {
            tenancy()->end();
            if ($previous) {
                tenancy()->initialize($previous);
            }
        }
    }
}
