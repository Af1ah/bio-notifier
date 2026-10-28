<?php

namespace App\Services\Devices\Gateway;

use App\Models\Device;
use App\Models\DeviceCommand;
use Illuminate\Support\Facades\DB;

final class DeviceDiscovery
{
    public function request(Device $device): void
    {
        DB::transaction(function () use ($device) {
            $device = Device::whereKey($device->id)->lockForUpdate()->firstOrFail();
            if (($device->options['profile_policy_version'] ?? 0) >= 1) {
                return;
            }
            // One bounded discovery attempt per day. Manual refresh remains available.
            if (DeviceCommand::where('device_id', $device->id)->where('command_type', 'info')->where(function ($query) {
                $query->whereIn('status', ['pending', 'sent', 'outcome_unknown'])->orWhere('created_at', '>=', now()->subDay());
            })->exists()) {
                return;
            }
            $commands = app(GatewayCommands::class);
            if ($commands->available($device, 'info')) {
                $commands->request($device, 'info');
            }
        });
    }
}
