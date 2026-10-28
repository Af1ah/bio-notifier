<?php

namespace App\Services\Devices;

use App\Models\Device;
use App\Models\DeviceCommand;
use App\Models\DeviceRosterEntry;
use App\Models\User;
use Illuminate\Validation\ValidationException;
use Ramsey\Uuid\Uuid;

final class DeviceAccessValidity
{
    public const MODES = ['none' => 'No expiration', 'dates' => 'Dates only', 'entries' => 'Entry limit only', 'both' => 'Dates and entry limit'];

    public static function parameters(array $input): array
    {
        if (($input['mode'] ?? null) === 'period') {
            $input['mode'] = 'dates';
        }
        $mode = $input['mode'] ?? null;
        $rules = ['pin' => ['required', 'string', 'regex:/^[A-Za-z0-9_-]{1,64}$/'], 'mode' => ['required', 'in:none,dates,entries,both']];
        if (in_array($mode, ['dates', 'both'], true)) {
            $rules += ['from' => ['required', 'date_format:Y-m-d'], 'until' => ['required', 'date_format:Y-m-d', 'after_or_equal:from']];
        }
        if (in_array($mode, ['entries', 'both'], true)) {
            $rules['entries'] = ['required', 'string', 'regex:/^[1-9][0-9]{0,4}$/', 'integer', 'between:1,65535'];
        }
        if (array_diff(array_keys($input), array_keys($rules))) {
            throw ValidationException::withMessages(['mode' => 'Send only the fields used by the selected expiration rule.']);
        }

        return validator($input, $rules)->validate();
    }

    public function applyToDeviceUser(DeviceRosterEntry $record, Device $device, array $input, ?string $requestId = null): DeviceCommand
    {
        abort_unless($record->device_id === $device->id && DeviceRosterEntry::whereKey($record->id)
            ->where('device_id', $device->id)->where('pin', $record->pin)->exists(), 403);
        if ($requestId !== null) {
            validator(['id' => $requestId], ['id' => ['required', 'uuid']])->validate();
            abort_unless(app(DeviceCommandService::class)->available($device, 'query_users'), 403);
        }
        $command = app(DeviceCommandService::class)->request($device, 'set_user_validity',
            self::parameters(['pin' => (string) $record->pin] + $input), requestId: $requestId);
        if ($requestId !== null) {
            app(DeviceCommandService::class)->request($device, 'query_users', ['pin' => (string) $record->pin],
                dependsOn: $command->gateway_command_id, requestId: (string) Uuid::uuid5($requestId, 'user-readback'));
        }

        return $command;
    }

    public function apply(User $employee, Device $device, array $input): DeviceCommand
    {
        abort_unless(User::whereKey($employee->id)->where('pin', $employee->pin)->exists(), 403);
        $record = DeviceRosterEntry::where('device_id', $device->id)->where('pin', $employee->pin)->first();
        if (! $record) {
            throw ValidationException::withMessages(['device_id' => 'Read this employee from the device before changing its expiration rule.']);
        }

        return $this->applyToDeviceUser($record, $device, $input);
    }
}
