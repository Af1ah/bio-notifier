<?php

namespace App\Jobs;

use App\Models\DeviceCommand;
use App\Models\Organisation;
use App\Services\EbioSoapService;
use App\Services\Tenancy\TenantContext;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Throwable;

class SyncEbioUsersJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public $organisation;

    public $commandId;

    public function __construct(Organisation $organisation, ?int $commandId = null)
    {
        $this->organisation = $organisation;
        $this->commandId = $commandId;
    }

    public function handle(EbioSoapService $service): void
    {
        TenantContext::run($this->organisation, function () use ($service): void {
            $command = $this->commandId ? DeviceCommand::find($this->commandId) : null;
            $command?->markAsSent();

            try {
                $result = $service->syncUsers($this->organisation);
                $command?->markAsAcknowledged("{$result['synced']} user(s) synced; {$result['errors']} skipped.");
            } catch (Throwable $e) {
                $command?->markAsFailed($e->getMessage());
                Log::error("Failed to sync users for organisation {$this->organisation->id}: ".$e->getMessage());
                throw $e;
            }
        });
    }

    public function failed(?Throwable $exception): void
    {
        TenantContext::run($this->organisation, function () use ($exception): void {

            if ($this->commandId && ($command = DeviceCommand::find($this->commandId))) {
                $command->markAsFailed($exception?->getMessage() ?? 'The queue job failed.');
            }
        });
    }
}
