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
        'query_biodata' => 'Fetch biometric templates',
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

    // These commands need no additional tenant-entered data. Operations such
    // as user/template updates stay unavailable until their typed form exists.
    public const RUNNABLE_OPERATIONS = [
        'info' => 'Device information',
        'check' => 'Check connection',
        'query_users' => 'Read device users',
        'log' => 'Request device logs',
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

        return in_array($operation, $binding->capabilities ?? [], true);
    }

    public function request(Device $device, string $operation, array $parameters = [], ?string $requestId = null): DeviceCommand
    {
        abort_unless($this->available($device, $operation), 403);
        $binding = GatewayDeviceBinding::where('organisation_id', tenant('id'))->where('device_id', $device->id)->firstOrFail();
        $id = $requestId ?? (string) Str::uuid();
        if ($requestId && ($existing = DeviceCommand::where('gateway_command_id', $requestId)->first())) {
            abort_unless($existing->device_id === $device->id && $existing->command_type === $operation, 409);
            $this->relay((string) tenant('id'), $requestId);

            return $existing;
        }
        $expires = now()->addMinutes(15);
        // An explicit empty Card clears it; an absent Card leaves it unchanged.
        // Preserve intentional empty strings and remove only null values.
        $parameters = array_filter($parameters, fn ($value) => $value !== null);
        $command = DB::transaction(function () use ($device, $binding, $operation, $parameters, $id, $expires) {
            $content = self::OPERATIONS[$operation];
            if (in_array($operation, ['update_biodata', 'update_biophoto', 'query_biodata'], true) && isset($parameters['pin'])) {
                $content .= ' (Employee: '.$parameters['pin'].')';
            }
            $command = DeviceCommand::create(['device_id' => $device->id, 'command_type' => $operation,
                'command_content' => $content, 'gateway_command_id' => $id, 'status' => 'pending']);
            DB::table('gateway_command_outbox')->insert(['id' => $id, 'projection_id' => $command->id,
                'binding_id' => $binding->id, 'binding_version' => $binding->version, 'operation' => $operation,
                'parameters' => json_encode($parameters), 'expires_at' => $expires, 'created_at' => now(), 'updated_at' => now()]);

            return $command;
        });
        $this->relay((string) tenant('id'), $id);

        return $command;
    }

    public function cancel(\App\Models\DeviceCommand $command): void
    {
        abort_unless(tenant() && tenant()->device_integration === DeviceIntegration::GATEWAY, 403);
        abort_unless(in_array($command->status, ['pending', 'sent', 'outcome_unknown'], true) && $command->gateway_command_id, 409);
        $submission = GatewayCommandSubmission::whereKey($command->gateway_command_id)
            ->where('organisation_id', tenant('id'))->where('projection_id', $command->id)->firstOrFail();
        $binding = GatewayDeviceBinding::whereKey($submission->binding_id)->where('organisation_id', tenant('id'))
            ->where('device_id', $command->device_id)->where('version', $submission->binding_version)->firstOrFail();
        // Fence the submission worker before changing the tenant projection. No network call under a DB lock.
        $local = GatewayCommandSubmission::whereKey($submission->id)->where('state', 'pending')->where('attempts', 0)
            ->whereNull('lease_token')->update(['state' => 'cancelled']);
        if (! $local) {
            $submission->refresh();
            if (in_array($submission->state, ['submitting', 'retrying', 'parked'], true)) {
                throw \Illuminate\Validation\ValidationException::withMessages(['command' => 'Command submission is being reconciled. Try again after its submission status is resolved.']);
            }
            abort_unless($submission->state === 'submitted' || $submission->state === 'cancelled', 409);
            if ($submission->state !== 'cancelled') {
                app(GatewayClient::class)->call(\App\Models\GatewayConnection::findOrFail($binding->gateway_connection_id), 'POST', '/v1/commands/'.$submission->id.'/resolve');
            }
        }
        DeviceCommand::whereKey($command->id)->where('gateway_command_id', $submission->id)
            ->whereIn('status', ['pending', 'sent', 'outcome_unknown'])
            ->update(['status' => 'cancelled', 'response' => 'Cancelled by operator. A command already sent may have executed.']);
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
            'operation' => $row->operation, 'parameters' => json_decode($row->parameters, true) ?: [], 'expires_at' => $row->expires_at, 'state' => 'pending',
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
