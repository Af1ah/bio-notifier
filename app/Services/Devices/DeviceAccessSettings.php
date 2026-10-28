<?php

namespace App\Services\Devices;

use App\Models\Device;
use App\Models\DeviceAccessSetting;
use App\Models\DeviceCommand;
use App\Models\DeviceRosterEntry;
use App\Models\GatewayDeviceBinding;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Ramsey\Uuid\Uuid;

final class DeviceAccessSettings
{
    public const DAYS = ['Sun' => 'Sunday', 'Mon' => 'Monday', 'Tues' => 'Tuesday', 'Wed' => 'Wednesday', 'Thurs' => 'Thursday', 'Fri' => 'Friday', 'Sat' => 'Saturday'];

    public const OPERATIONS = ['query_access_groups', 'query_access_periods', 'set_access_group', 'set_access_period', 'set_user_access_group'];

    public static function parameters(string $operation, array $input): array
    {
        $number = ['required', 'string', 'regex:/^[1-9][0-9]{0,4}$/', 'integer', 'between:1,65535'];
        $rules = match ($operation) {
            'query_access_groups', 'query_access_periods' => [],
            'set_user_access_group' => ['pin' => ['required', 'string', 'regex:/^[A-Za-z0-9_-]{1,64}$/'], 'number' => $number],
            'set_access_group' => ['number' => $number, 'Verify' => ['required', 'string', 'regex:/^(0|[1-9][0-9]{0,4})$/', 'integer', 'between:0,65535'],
                'ValidHoliday' => ['required', 'string', 'in:0,1'], 'TZ' => ['required', 'string', 'max:64']],
            'set_access_period' => ['number' => $number],
            default => throw ValidationException::withMessages(['access' => 'Unsupported access operation.']),
        };
        if ($operation === 'set_user_access_group' && array_intersect(array_keys($input), ['period1', 'period2', 'period3'])) {
            foreach (['period1', 'period2', 'period3'] as $key) {
                $rules[$key] = ['required', 'string', 'regex:/^(0|[1-9][0-9]{0,4})$/', 'integer', 'between:0,65535'];
            }
        }
        if ($operation === 'set_access_period') {
            foreach (self::DAYS as $day => $label) {
                foreach (['Start', 'End'] as $end) {
                    $rules[$day.$end] = ['required', 'string', 'regex:/^([01][0-9]|2[0-3])[0-5][0-9]$/'];
                }
            }
        }
        if (array_diff(array_keys($input), array_keys($rules))) {
            throw ValidationException::withMessages(['access' => 'Send only the selected access settings.']);
        }
        $data = validator($input, $rules)->validate();
        if ($operation === 'set_user_access_group' && isset($data['period1']) && $data['period1'] === '0' && $data['period2'] === '0' && $data['period3'] === '0') {
            throw ValidationException::withMessages(['periods' => 'Select at least one personal daily period.']);
        }
        if ($operation === 'set_access_group') {
            $slots = explode(';', $data['TZ']);
            if (count($slots) !== 4 || $slots[0] !== '1' || count(array_filter(array_slice($slots, 1), fn ($slot) => $slot !== '0')) === 0) {
                throw ValidationException::withMessages(['periods' => 'Select one to three daily periods.']);
            }
            foreach (array_slice($slots, 1) as $slot) {
                if ($slot !== '0') {
                    validator(['number' => $slot], ['number' => $number])->validate();
                }
            }
        }

        return $data;
    }

    private function binding(Device $device): GatewayDeviceBinding
    {
        abort_unless(auth('web')->user()?->has_login && tenant()?->device_integration === DeviceIntegration::GATEWAY, 403);
        abort_unless(Device::whereKey($device->id)->where('serial_number', $device->serial_number)->exists(), 403);

        return GatewayDeviceBinding::where('organisation_id', tenant('id'))->where('device_id', $device->id)
            ->where('serial', $device->serial_number)->where('enabled', true)->whereNotNull('synced_at')->firstOrFail();
    }

    public function save(Device $device, string $kind, array $data, ?DeviceAccessSetting $record = null): DeviceAccessSetting
    {
        $binding = $this->binding($device);
        abort_unless(in_array($kind, ['group', 'period'], true) && DeviceProfilePolicy::supportsValidity($device), 403);
        $name = validator($data, ['name' => ['required', 'string', 'max:100']])->validate()['name'];
        $parameters = self::parameters('set_access_'.$kind, $data['settings'] ?? []);
        if (! $record) {
            validator($data, ['slots_confirmed' => ['required', 'accepted']])->validate();
        }

        return DB::transaction(function () use ($device, $binding, $kind, $name, $parameters, $record, $data): DeviceAccessSetting {
            Device::whereKey($device->id)->lockForUpdate()->firstOrFail();
            if ($record) {
                $record = DeviceAccessSetting::whereKey($record->id)->where('device_id', $device->id)->where('kind', $kind)->lockForUpdate()->firstOrFail();
                abort_unless($record->binding_id === $binding->id && $record->binding_version === $binding->version, 409);
                if ($record->revision !== (int) ($data['revision'] ?? 0)) {
                    throw ValidationException::withMessages(['name' => 'These settings changed. Close and reopen the form.']);
                }
                $this->notPending($record);
                if ($record->origin === 'device' && (! $record->reported_at || $record->reported_at->lt(now()->subMinutes(10)))) {
                    throw ValidationException::withMessages(['name' => 'Read this device’s groups and daily periods again before editing existing terminal settings.']);
                }
                abort_unless((string) $record->number === $parameters['number'], 422);
                if ($kind === 'group') {
                    $preserved = $record->reported ?? $record->desired;
                    abort_unless($parameters['Verify'] === $preserved['Verify'] && $parameters['ValidHoliday'] === $preserved['ValidHoliday'], 422);
                }
                $record->update(['name' => $name, 'desired' => $parameters, 'revision' => $record->revision + 1]);

                return $record;
            }
            if (DeviceAccessSetting::where('device_id', $device->id)->where('kind', $kind)->where('number', $parameters['number'])->exists()) {
                throw ValidationException::withMessages(['number' => 'That number already exists for this device. Edit its existing entry.']);
            }

            return DeviceAccessSetting::create(['device_id' => $device->id, 'kind' => $kind, 'number' => $parameters['number'],
                'name' => $name, 'desired' => $parameters, 'origin' => 'app', 'binding_id' => $binding->id, 'binding_version' => $binding->version]);
        });
    }

    private function notPending(DeviceAccessSetting $record): void
    {
        if ($record->command_id && ! in_array($record->command?->status, ['acknowledged', 'failed', 'expired', 'cancelled'], true)) {
            throw ValidationException::withMessages(['name' => 'The previous update is pending or needs review. Resolve it in Device actions before editing.']);
        }
    }

    public function ready(DeviceAccessSetting $record, GatewayDeviceBinding $binding): bool
    {
        if ($record->binding_id !== $binding->id || $record->binding_version !== $binding->version) {
            return false;
        }
        if ($record->command_id && $record->submitted_revision === $record->revision) {
            if ($record->reported_at && $record->command && $record->reported_at->gte($record->command->created_at)) {
                return $record->desired == $record->reported && $record->command->status === 'acknowledged';
            }

            return $record->command?->status === 'acknowledged';
        }

        return $record->reported_at?->gte(now()->subMinutes(10)) && $record->desired == $record->reported;
    }

    public function apply(Device $device, DeviceAccessSetting $record, int $revision): DeviceCommand
    {
        $binding = $this->binding($device);
        $operation = 'set_access_'.$record->kind;
        abort_unless(app(DeviceCommandService::class)->available($device, $operation), 403);
        [$record, $requestId] = DB::transaction(function () use ($device, $binding, $record, $revision): array {
            Device::whereKey($device->id)->lockForUpdate()->firstOrFail();
            $current = DeviceAccessSetting::whereKey($record->id)->where('device_id', $device->id)->lockForUpdate()->firstOrFail();
            abort_unless($current->binding_id === $binding->id && $current->binding_version === $binding->version && $current->revision === $revision, 409);
            if ($current->submitted_revision === $current->revision && $current->command_id) {
                return [$current, $current->command_id];
            }
            $this->notPending($current);
            if ($current->origin === 'device' && (! $current->reported_at || $current->reported_at->lt(now()->subMinutes(10)))) {
                throw ValidationException::withMessages(['access' => 'Read current terminal settings before applying this change.']);
            }
            if ($current->kind === 'group') {
                foreach (array_unique(array_filter(array_slice(explode(';', $current->desired['TZ']), 1), fn ($slot) => $slot !== '0')) as $slot) {
                    $period = DeviceAccessSetting::where('device_id', $device->id)->where('kind', 'period')->where('number', $slot)->first();
                    if (! $period || ! $this->ready($period, $binding)) {
                        throw ValidationException::withMessages(['access' => 'Read or apply every referenced daily period before applying this group.']);
                    }
                }
            }
            $id = (string) Str::uuid();
            $current->update(['command_id' => $id, 'submitted_revision' => $current->revision]);

            return [$current, $id];
        });

        // Stable request identity permits recovery after a queue/submission failure.
        // No network calls or queue submission while holding settings locks.
        return app(DeviceCommandService::class)->request($device, $operation, $record->desired, requestId: $requestId);
    }

    public function assign(Device $device, DeviceRosterEntry $user, DeviceAccessSetting $group, ?array $periods = null, ?string $requestId = null): DeviceCommand
    {
        $binding = $this->binding($device);
        if ($requestId !== null) {
            validator(['request_id' => $requestId], ['request_id' => ['required', 'uuid']])->validate();
            abort_unless(app(DeviceCommandService::class)->available($device, 'query_users'), 403);
        }
        abort_unless($user->device_id === $device->id && DeviceRosterEntry::whereKey($user->id)->where('device_id', $device->id)->where('pin', $user->pin)->exists(), 403);
        $group = DeviceAccessSetting::whereKey($group->id)->where('device_id', $device->id)->where('kind', 'group')->firstOrFail();
        if (! $this->ready($group, $binding)) {
            throw ValidationException::withMessages(['group_id' => 'Read or apply this group’s current settings before assigning users.']);
        }

        $parameters = ['pin' => (string) $user->pin, 'number' => (string) $group->number];
        if ($periods !== null) {
            validator(['periods' => $periods], ['periods' => ['required', 'array', 'min:1', 'max:3'], 'periods.*' => ['required', 'integer', 'between:1,65535', 'distinct']])->validate();
            foreach ($periods as $slot) {
                $period = DeviceAccessSetting::where('device_id', $device->id)->where('kind', 'period')->where('number', $slot)->first();
                if (! $period || ! $this->ready($period, $binding)) {
                    throw ValidationException::withMessages(['periods' => 'Read or apply every selected period on this device first.']);
                }
            }
            foreach (array_pad(array_values($periods), 3, '0') as $index => $slot) {
                $parameters['period'.($index + 1)] = (string) $slot;
            }
        }
        $command = app(DeviceCommandService::class)->request($device, 'set_user_access_group', self::parameters('set_user_access_group', $parameters), requestId: $requestId);
        if ($requestId) {
            // Refresh only after the write is acknowledged. The reported snapshot stays authoritative.
            $readId = (string) Uuid::uuid5($requestId, 'user-readback');
            app(DeviceCommandService::class)->request($device, 'query_users', ['pin' => (string) $user->pin], dependsOn: $command->gateway_command_id, requestId: $readId);
        }

        return $command;
    }
}
