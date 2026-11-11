<?php

namespace App\Services\Devices;

use App\Jobs\Gateway\SyncGatewayBinding;
use App\Models\Device;
use App\Models\GatewayDeviceBinding;
use Illuminate\Support\Facades\DB;

final class RemoveDevice
{
    public function remove(Device $device): void
    {
        abort_unless(tenant() && auth('web')->user()?->has_login && auth('web')->user()?->canManageDevices(), 403);
        DB::transaction(function () use ($device): void {
            $device = Device::whereKey($device->id)->where('serial_number', $device->serial_number)->lockForUpdate()->firstOrFail();
            $central = DB::connection(config('tenancy.database.central_connection'));
            $binding = $central->transaction(function () use ($device, $central) {
                $binding = GatewayDeviceBinding::where('organisation_id', tenant('id'))->where('device_id', $device->id)
                    ->where('serial', $device->serial_number)->lockForUpdate()->first();
                if ($binding && $binding->enabled) {
                    $binding->update(['enabled' => false, 'version' => $binding->version + 1, 'synced_at' => null, 'last_error' => null]);
                }
                $central->table('gateway_audit_events')->insert([
                    'action' => 'tenant_device_removed', 'subject' => $device->serial_number,
                    'metadata' => json_encode(['organisation_id' => tenant('id'), 'device_id' => $device->id, 'tenant_user_id' => auth('web')->id(), 'logs_preserved' => true]),
                    'created_at' => now(), 'updated_at' => now(),
                ]);

                return $binding;
            });
            $device->delete();
            if ($binding && ! $binding->synced_at) {
                DB::afterCommit(function () use ($binding): void {
                    try {
                        SyncGatewayBinding::dispatch($binding->id)->onConnection('gateway')->onQueue('device-commands');
                    } catch (\Throwable $exception) {
                        // Recovery also scans durable unsynced bindings.
                        report($exception);
                    }
                });
            }
        });
    }
}
