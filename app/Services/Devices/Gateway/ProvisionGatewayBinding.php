<?php

namespace App\Services\Devices\Gateway;

use App\Models\Device;
use App\Models\GatewayBindingVersion;
use App\Models\GatewayConnection;
use App\Models\GatewayDeviceBinding;
use App\Models\Organisation;
use App\Services\Devices\DeviceIntegration;

final class ProvisionGatewayBinding
{
    public function provision(array $data): GatewayDeviceBinding
    {
        $organisation = Organisation::findOrFail($data['organisation_id']);
        abort_unless($organisation->device_integration === DeviceIntegration::GATEWAY, 422, 'Select a company using the gateway connection.');
        $existing = GatewayDeviceBinding::where('serial', $data['serial'])->first();
        abort_if($existing && $existing->organisation_id !== $organisation->id, 409, 'Device reassignment requires an audited drain.');
        try {
            tenancy()->initialize($organisation);
            $device = Device::firstOrCreate(['serial_number' => $data['serial']], ['name' => $data['device_name'], 'status' => 'unknown']);
        } finally {
            tenancy()->end();
        }
        abort_if($existing && $existing->gateway_connection_id !== (int) $data['gateway_connection_id'], 409, 'Gateway reassignment requires an audited drain.');
        $values = [
            'gateway_connection_id' => $data['gateway_connection_id'], 'organisation_id' => $organisation->id, 'device_id' => $device->id,
            'serial' => $data['serial'], 'source_cidr' => $data['source_cidr'], 'timezone' => $data['timezone'],
            'capabilities' => array_values($data['capabilities']), 'enabled' => (bool) $data['enabled'],
        ];
        if ($existing) {
            // A failed mirror update is retried with the same immutable version.
            abort_unless($existing->synced_at || $existing->last_error === 'binding_sync_failed', 409, 'This device is still being connected. Try again shortly.');
            $values['version'] = $existing->version + 1;
            $values['synced_at'] = null;
            $existing->update($values);

            return $existing;
        }

        // Make the first version available immediately on the returned model;
        // PostgreSQL insertGetId does not hydrate other database defaults.
        return GatewayDeviceBinding::create($values + ['version' => 1]);
    }

    public function sync(GatewayDeviceBinding $binding, GatewayClient $client): void
    {
        $connection = GatewayConnection::findOrFail($binding->gateway_connection_id);
        try {
            $version = GatewayBindingVersion::firstOrCreate(['binding_id' => $binding->id, 'version' => $binding->version], [
                'organisation_id' => $binding->organisation_id, 'device_id' => $binding->device_id,
                'gateway_connection_id' => $binding->gateway_connection_id, 'source_cidr' => $binding->source_cidr,
            ]);
            // Approval is durable before the remote call; a lost HTTP response must
            // not make uploads from an already-updated gateway permanently invalid.
            $version->update(['activated_at' => $version->activated_at ?? now()]);
            $result = $client->call($connection, 'PUT', '/v1/bindings/'.rawurlencode($binding->serial), [
                'serial' => $binding->serial, 'organisation_id' => $binding->organisation_id,
                'device_id' => $binding->device_id, 'version' => $binding->version, 'enabled' => $binding->enabled,
                'source_cidr' => $binding->source_cidr, 'timezone' => $binding->timezone, 'capabilities' => $binding->capabilities,
            ]);
            if (($result['accepted'] ?? false) !== true) {
                throw new \RuntimeException('Invalid binding acceptance.');
            }
            GatewayDeviceBinding::whereKey($binding->id)->where('version', $binding->version)->update(['synced_at' => now(), 'last_error' => null]);
        } catch (\Throwable $exception) {
            GatewayDeviceBinding::whereKey($binding->id)->where('version', $binding->version)->update(['last_error' => 'binding_sync_failed']);
            throw $exception;
        }
    }
}
