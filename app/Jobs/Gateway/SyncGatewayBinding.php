<?php

namespace App\Jobs\Gateway;

use App\Models\GatewayDeviceBinding;
use App\Services\Devices\Gateway\GatewayClient;
use App\Services\Devices\Gateway\ProvisionGatewayBinding;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

class SyncGatewayBinding implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public int $timeout = 30;

    public function __construct(public int $bindingId) {}

    public function signalId(): string
    {
        return 'binding:'.$this->bindingId;
    }

    public function backoff(): array
    {
        return [30, 300];
    }

    public function handle(ProvisionGatewayBinding $provisioner, GatewayClient $client): void
    {
        if (tenancy()->initialized) {
            tenancy()->end();
        }
        $binding = GatewayDeviceBinding::findOrFail($this->bindingId);
        if (! $binding->synced_at) {
            $provisioner->sync($binding, $client);
        }
    }
}
