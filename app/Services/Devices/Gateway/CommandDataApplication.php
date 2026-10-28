<?php

namespace App\Services\Devices\Gateway;

use App\Models\DeviceCommand;
use App\Models\GatewayCommandSubmission;
use App\Models\GatewayIngressReceipt;
use Illuminate\Support\Facades\DB;

final class CommandDataApplication
{
    public function received(GatewayIngressReceipt $receipt): void
    {
        $id = $receipt->payload['command_id'] ?? null;
        if (! $id || ! in_array($receipt->kind, ['users', 'biometrics', 'biometric_photos', 'device_info', 'access_groups', 'access_periods'], true)) {
            return;
        }
        $command = DeviceCommand::where('gateway_command_id', $id)->lockForUpdate()->first();
        if (! $command || $command->device_id != $receipt->device_id || ! GatewayCommandSubmission::whereKey($id)->where('organisation_id', $receipt->organisation_id)->where('binding_id', $receipt->binding_id)->where('binding_version', $receipt->binding_version)->exists()) {
            return;
        }
        $ids = array_values(array_unique(array_merge($command->data_receipt_ids ?? [], [$receipt->id])));
        $command->update(['data_receipt_ids' => $ids, 'data_applied' => false]);
    }

    public function applied(GatewayIngressReceipt $receipt): void
    {
        $id = $receipt->payload['command_id'] ?? null;
        if (! $id) {
            return;
        }
        DB::transaction(function () use ($id, $receipt) {
            $command = DeviceCommand::where('gateway_command_id', $id)->lockForUpdate()->first();
            if (! $command || ! $command->data_receipt_ids) {
                return;
            }
            $applied = GatewayIngressReceipt::where('organisation_id', $receipt->organisation_id)->whereIn('id', $command->data_receipt_ids)->where('state', 'applied')->count();
            $command->update(['data_applied' => $applied === count($command->data_receipt_ids)]);
        });
    }
}
