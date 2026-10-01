<?php

namespace App\Jobs;

use App\Models\Organisation;
use App\Services\EbioSoapService;
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
        tenancy()->initialize($this->organisation);
        $command = $this->commandId ? \App\Models\DeviceCommand::find($this->commandId) : null;
        $command?->markAsSent();

        try {
            $result = $service->syncUsers($this->organisation);
            $command?->markAsAcknowledged("{$result['synced']} user(s) synced; {$result['errors']} skipped.");
        } catch (Throwable $e) {
            $command?->markAsFailed($e->getMessage());
            Log::error("Failed to sync users for organisation {$this->organisation->id}: " . $e->getMessage());
            throw $e;
        }
    }

    public function failed(?Throwable $exception): void
    {
        tenancy()->initialize($this->organisation);

        if ($this->commandId && ($command = \App\Models\DeviceCommand::find($this->commandId))) {
            $command->markAsFailed($exception?->getMessage() ?? 'The queue job failed.');
        }
    }
}
