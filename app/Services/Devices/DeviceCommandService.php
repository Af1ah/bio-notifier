<?php

namespace App\Services\Devices;

use App\Models\Device;
use App\Models\DeviceCommand;

final class DeviceCommandService
{
    public function __construct(private DeviceTransportResolver $resolver) {}

    public function available(Device $device, string $operation): bool
    {
        return $this->resolver->current()->supports($device, $operation);
    }

    public function request(Device $device, string $operation, array $parameters = []): DeviceCommand
    {
        abort_unless(Device::whereKey($device->id)->where('serial_number', $device->serial_number)->exists(), 403);
        $transport = $this->resolver->current();
        abort_unless($transport->supports($device, $operation), 403);

        return $transport->request($device, $operation, $parameters);
    }
}
