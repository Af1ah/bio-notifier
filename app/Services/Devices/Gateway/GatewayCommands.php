<?php

namespace App\Services\Devices\Gateway;

use App\Jobs\Gateway\SubmitGatewayCommand;
use App\Models\Device;
use App\Models\DeviceCommand;
use App\Models\GatewayCommandSubmission;
use App\Models\GatewayDeviceBinding;
use App\Services\Devices\DeviceIntegration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

final class GatewayCommands
{
    public const OPERATIONS = [
        'info' => 'Device information',
        'check' => 'Check connection',
        'query_users' => 'Read device users',
        'log' => 'Request device logs',
        'get_option' => 'Read device setting',
        'upsert_user' => 'Add or update device user',
        'delete_user' => 'Remove device user',
        'reset_transaction_stamp' => 'Reset Transaction Stamp',
        'reset_op_stamp' => 'Reset OP Stamp',
        'unlock_door' => 'Unlock Door',
        'clear_alarm' => 'Clear Alarm',
        'update_biodata' => 'Update Biometric Template',
        'update_biophoto' => 'Update User Photo',
    ];

    public function available(Device $device, string $operation): bool
    {
        if (! tenant() || tenant()->device_integration !== DeviceIntegration::GATEWAY) {
            return false;
        }
        $binding = GatewayDeviceBinding::where('organisation_id', tenant('id'))->where('device_id', $device->id)
            ->where('serial', $device->serial_number)->where('enabled', true)->whereNotNull('synced_at')->first();

        if (! $binding || ! array_key_exists($operation, self::OPERATIONS)) {
            return false;
        }

        return empty($binding->capabilities)
            || in_array($operation, $binding->capabilities, true)
            || array_key_exists($operation, self::OPERATIONS);
    }

    public function request(Device $device, string $operation, array $parameters = []): DeviceCommand
    {
        abort_unless($this->available($device, $operation), 403);
        $binding = GatewayDeviceBinding::where('organisation_id', tenant('id'))->where('device_id', $device->id)->firstOrFail();
        $id = (string) Str::uuid();
        $expires = now()->addMinutes(15);
        $parameters = array_filter($parameters, fn ($value) => $value !== null && $value !== '');
        $command = DB::transaction(function () use ($device, $binding, $operation, $parameters, $id, $expires) {
            $command = DeviceCommand::create(['device_id' => $device->id, 'command_type' => $operation,
                'command_content' => self::OPERATIONS[$operation], 'gateway_command_id' => $id, 'status' => 'pending']);
            DB::table('gateway_command_outbox')->insert(['id' => $id, 'projection_id' => $command->id,
                'binding_id' => $binding->id, 'binding_version' => $binding->version, 'operation' => $operation,
                'parameters' => json_encode($parameters), 'expires_at' => $expires, 'created_at' => now(), 'updated_at' => now()]);

            return $command;
        });
        $this->relay((string) tenant('id'), $id);

        return $command;
    }

    public function relay(string $organisationId, string $id): void
    {
        $row = DB::table('gateway_command_outbox')->where('id', $id)->first();
        if (! $row) {
            return;
        }
        $submission = GatewayCommandSubmission::firstOrCreate(['id' => $id], [
            'binding_id' => $row->binding_id, 'organisation_id' => $organisationId,
            'projection_id' => $row->projection_id, 'binding_version' => $row->binding_version,
            'operation' => $row->operation, 'parameters' => $row->parameters, 'expires_at' => $row->expires_at, 'state' => 'pending',
        ]);
        DB::table('gateway_command_outbox')->where('id', $id)->update(['relayed_at' => now()]);
        // The scheduler also submits pending central rows if queue insertion fails.
        try {
            SubmitGatewayCommand::dispatch($submission->id)->onConnection('gateway')->onQueue('device-commands');
        } catch (\Throwable $exception) {
            report($exception);
        }
    }
}
