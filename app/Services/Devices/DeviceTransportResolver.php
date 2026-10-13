<?php

namespace App\Services\Devices;

use App\Services\Devices\Contracts\DeviceTransport;
use App\Services\Devices\Transports\EbioTransport;
use App\Services\Devices\Transports\GatewayTransport;
use Illuminate\Auth\Access\AuthorizationException;

final class DeviceTransportResolver
{
    public function current(): DeviceTransport
    {
        abort_unless(tenant(), 403);

        return match (tenant()->device_integration ?? DeviceIntegration::EBIO) {
            DeviceIntegration::GATEWAY => app(GatewayTransport::class),
            DeviceIntegration::EBIO => app(EbioTransport::class),
            default => throw new AuthorizationException('Unsupported device connection.'),
        };
    }
}
