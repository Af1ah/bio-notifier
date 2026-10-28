<?php

namespace App\Services\Devices\Gateway;

use App\Casts\ProtectedArray;
use App\Models\DeviceCommand;
use App\Models\GatewayCommandSubmission;
use App\Models\GatewayIngressReceipt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Ramsey\Uuid\Uuid;

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
            $saved = $applied === count($command->data_receipt_ids);
            $command->update(['data_applied' => $saved]);
            if ($saved && $receipt->kind === 'users') {
                $this->confirmAccessSync($command, $receipt);
            }
        });
    }

    private function confirmAccessSync(DeviceCommand $read, GatewayIngressReceipt $receipt): void
    {
        $readOutbox = DB::table('gateway_command_outbox')->where('id', $read->gateway_command_id)->first();
        if (! $readOutbox || $readOutbox->binding_id != $receipt->binding_id || $readOutbox->binding_version != $receipt->binding_version) {
            return;
        }
        $readParameters = ProtectedArray::decode($readOutbox->parameters) ?? [];
        $parentId = $readParameters['__depends_on'] ?? null;
        if (! is_string($parentId) || ! Str::isUuid($parentId)
            || (string) Uuid::uuid5($parentId, 'user-readback') !== $read->gateway_command_id) {
            return;
        }
        $parent = DeviceCommand::where('gateway_command_id', $parentId)->where('device_id', $receipt->device_id)
            ->where('command_type', 'set_user_access_group')->lockForUpdate()->first();
        $outbox = DB::table('gateway_command_outbox')->where('id', $parentId)->first();
        if (! $parent || ! $outbox || $outbox->binding_id != $receipt->binding_id || $outbox->binding_version != $receipt->binding_version
            || ! GatewayCommandSubmission::whereKey($parentId)->where('organisation_id', $receipt->organisation_id)
                ->where('binding_id', $receipt->binding_id)->where('binding_version', $receipt->binding_version)->exists()) {
            return;
        }
        $expected = ProtectedArray::decode($outbox->parameters) ?? [];
        if (($readParameters['pin'] ?? null) !== ($expected['pin'] ?? null)) {
            return;
        }
        $users = array_values(array_filter($receipt->payload['users'] ?? [], fn ($user) => ($user['pin'] ?? null) === ($expected['pin'] ?? null)));
        $actual = count($users) === 1 ? ($users[0]['access'] ?? []) : [];
        $tz = isset($expected['period1']) ? sprintf('0001%04X%04X%04X', (int) $expected['period1'], (int) $expected['period2'], (int) $expected['period3']) : '0000000000000000';
        $matches = isset($actual['Grp'], $actual['TZ'], $expected['number']) && ctype_digit($actual['Grp'])
            && (int) $actual['Grp'] === (int) $expected['number'] && strtoupper($actual['TZ']) === $tz;
        $parent->update(['data_receipt_ids' => array_values(array_unique([...($parent->data_receipt_ids ?? []), $receipt->id])),
            'data_applied' => $matches]);
    }
}
