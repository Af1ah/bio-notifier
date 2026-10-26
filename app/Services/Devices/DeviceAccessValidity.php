<?php

namespace App\Services\Devices;

use App\Models\Device;
use App\Models\DeviceCommand;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final class DeviceAccessValidity
{
    public static function parameters(array $input): array
    {
        $data = validator($input, [
            'pin' => ['required', 'string', 'regex:/^[A-Za-z0-9_-]{1,64}$/'],
            'mode' => ['required', 'in:period'],
            'from' => ['required_if:mode,period', 'date_format:Y-m-d'],
            'until' => ['required_if:mode,period', 'date_format:Y-m-d', 'after_or_equal:from'],
        ])->validate();
        $expected = ['pin', 'mode', 'from', 'until'];
        if (array_diff(array_keys($input), $expected)) {
            throw ValidationException::withMessages(['mode' => 'Send only the access-period fields.']);
        }

        return $data;
    }

    public function apply(User $employee, Device $device, array $input): DeviceCommand
    {
        abort_unless(User::whereKey($employee->id)->where('pin', $employee->pin)->exists(), 403);
        abort_unless(Device::whereKey($device->id)->where('serial_number', $device->serial_number)->exists(), 403);
        $parameters = self::parameters(['pin' => (string) $employee->pin] + $input);
        if (! DB::table('gateway_device_users')->where('device_id', $device->id)->where('pin', $employee->pin)->exists()) {
            throw ValidationException::withMessages(['device_id' => 'Push this employee to the device and read device users before setting an access period.']);
        }

        // Uses the normal encrypted outbox, tenant ownership checks and queue.
        // No identity, card or administrator privilege fields are sent.
        return app(DeviceCommandService::class)->request($device, 'set_user_validity', $parameters);
    }
}
