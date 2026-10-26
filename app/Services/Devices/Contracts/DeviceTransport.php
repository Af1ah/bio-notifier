<?php

namespace App\Services\Devices\Contracts;

use App\Models\Device;
use App\Models\DeviceCommand;

interface DeviceTransport
{
    public function supports(Device $device, string $operation): bool;

    public function request(Device $device, string $operation, array $parameters = [], ?string $dependsOn = null): DeviceCommand;
}
