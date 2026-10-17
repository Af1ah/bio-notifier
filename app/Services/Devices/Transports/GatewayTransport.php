<?php

namespace App\Services\Devices\Transports;

use App\Models\Device;
use App\Models\DeviceCommand;
use App\Services\Devices\Contracts\DeviceTransport;
use App\Services\Devices\Gateway\GatewayCommands;

final class GatewayTransport implements DeviceTransport
{
    public function __construct(private GatewayCommands $commands) {}

    public function supports(Device $device, string $operation): bool
    {
        return $this->commands->available($device, $operation);
    }

    public function request(Device $device, string $operation, array $parameters = []): DeviceCommand
    {
        return $this->commands->request($device, $operation, $parameters);
    }
}
