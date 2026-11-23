<?php

namespace App\Jobs\Concerns;

use App\Models\DeviceCommand;
use App\Models\Organisation;
use App\Models\User;
use App\Services\Tenancy\TenantContext;

trait TracksQueuedDeviceCommand
{
    public ?int $trackedCommandId = null;

    protected function trackCommand(Organisation $organisation, string $type, ?int $deviceId = null): void
    {
        if (! tenancy()->initialized || (string) tenant('id') !== (string) $organisation->id) {
            return;
        }
        $actor = auth('web')->user();
        if (! $actor instanceof User || ! $actor->has_login) {
            return;
        }
        $this->trackedCommandId = DeviceCommand::create(['device_id' => $deviceId, 'command_type' => $type,
            'command_content' => str($type)->replace('_', ' ')->title()->toString(), 'status' => 'pending'])->id;
        $this->afterCommit();
    }

    protected function trackedCommand(): ?DeviceCommand
    {
        $id = $this->trackedCommandId ?? ($this->commandId ?? null);

        return $id ? DeviceCommand::find($id) : null;
    }

    public function failed(?\Throwable $exception): void
    {
        TenantContext::run($this->organisation, fn () => $this->trackedCommand()?->markAsFailed('The queue job failed or timed out.'));
    }
}
