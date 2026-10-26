<?php

namespace App\Services\Devices\Transports;

use App\Jobs\EbioDeviceCommandJob;
use App\Models\Device;
use App\Models\DeviceCommand;
use App\Services\Devices\Contracts\DeviceTransport;
use App\Services\Devices\DeviceIntegration;

final class EbioTransport implements DeviceTransport
{
    private const OPERATIONS = ['reboot', 'clear_logs', 'reset_transaction_stamp', 'reset_op_stamp', 'unlock_door'];

    public function supports(Device $device, string $operation): bool
    {
        return DeviceIntegration::usesEbio() && in_array($operation, self::OPERATIONS, true);
    }

    public function request(Device $device, string $operation, array $parameters = [], ?string $dependsOn = null): DeviceCommand
    {
        DeviceIntegration::requireEbio(tenant());
        abort_unless($this->supports($device, $operation), 403);
        $command = DeviceCommand::create(['device_id' => $device->id, 'command_type' => $operation,
            'command_content' => str($operation)->replace('_', ' ')->title()->toString(), 'status' => 'pending']);
        EbioDeviceCommandJob::dispatch(tenant(), $device->serial_number, $operation, $command->id);

        return $command;
    }
}
