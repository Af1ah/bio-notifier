<?php

namespace App\Services\Devices;

use App\Models\Device;
use App\Models\DeviceAccessSetting;
use App\Models\DeviceCommand;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

final class DeviceAccessRules
{
    public function __construct(private DeviceAccessSettings $settings) {}

    public function save(Device $device, array $input, ?DeviceAccessSetting $rule = null): DeviceAccessSetting
    {
        abort_unless(tenant() && auth('web')->user()?->has_login && auth('web')->user()?->canManageDevices(), 403);
        $data = validator($input, [
            'name' => ['required', 'string', 'max:100'],
            'days' => ['required', 'array', 'min:1', 'max:7'],
            'days.*' => ['required', 'string', 'distinct', Rule::in(array_keys(DeviceAccessSettings::DAYS))],
            'start_time' => ['required', 'date_format:H:i'],
            'end_time' => ['required', 'date_format:H:i', 'after:start_time'],
            'holidays' => ['required', 'in:0,1'],
            'revision' => ['nullable', 'integer', 'min:1'],
            'period_revision' => ['nullable', 'integer', 'min:1'],
        ])->validate();

        return DB::transaction(function () use ($device, $data, $rule): DeviceAccessSetting {
            $device = Device::whereKey($device->id)->where('serial_number', $device->serial_number)->lockForUpdate()->firstOrFail();
            $period = null;
            if ($rule) {
                $rule = DeviceAccessSetting::whereKey($rule->id)->where('device_id', $device->id)->where('kind', 'group')->lockForUpdate()->firstOrFail();
                abort_unless($rule->managed_period_id, 409, 'This imported rule needs a complete device read before it can be simplified.');
                $period = DeviceAccessSetting::whereKey($rule->managed_period_id)->where('device_id', $device->id)->where('kind', 'period')->lockForUpdate()->firstOrFail();
                $groupNumber = (string) $rule->number;
                $periodNumber = (string) $period->number;
            } else {
                $groupNumber = $this->allocate($device, 'group');
                $periodNumber = $this->allocate($device, 'period');
            }
            $hours = ['number' => $periodNumber];
            foreach (DeviceAccessSettings::DAYS as $day => $label) {
                $allowed = in_array($day, $data['days'], true);
                // End before start is the documented closed-day representation.
                $hours[$day.'Start'] = $allowed ? str_replace(':', '', $data['start_time']) : '2359';
                $hours[$day.'End'] = $allowed ? str_replace(':', '', $data['end_time']) : '0000';
            }
            $period = $this->settings->save($device, 'period', [
                'name' => $data['name'], 'settings' => $hours, 'revision' => $data['period_revision'] ?? null, 'slots_confirmed' => true,
            ], $period);
            $group = ['number' => $groupNumber, 'Verify' => ($rule?->reported ?? $rule?->desired ?? [])['Verify'] ?? '0',
                'ValidHoliday' => (string) $data['holidays'], 'TZ' => '1;'.$periodNumber.';0;0'];
            $rule = $this->settings->save($device, 'group', [
                'name' => $data['name'], 'settings' => $group, 'revision' => $data['revision'] ?? null, 'slots_confirmed' => true,
            ], $rule);
            $rule->update(['managed_period_id' => $period->id]);

            return $rule->load('managedPeriod');
        });
    }

    private function allocate(Device $device, string $kind): string
    {
        // A missing inventory is never proof that a native slot is unused.
        $approved = $device->options['approved_access_slots'][$kind] ?? [];
        $used = DeviceAccessSetting::where('device_id', $device->id)->where('kind', $kind)->pluck('number')->all();
        foreach ($approved as $number) {
            if (is_int($number) && $number >= 1 && $number <= 65535 && ! in_array($number, $used, true)) {
                return (string) $number;
            }
        }
        throw ValidationException::withMessages(['name' => 'No unused rule space has been confirmed for this device yet.']);
    }

    public function apply(Device $device, DeviceAccessSetting $rule, int $revision, int $periodRevision): DeviceCommand
    {
        abort_unless(tenant() && auth('web')->user()?->has_login && auth('web')->user()?->canManageDevices(), 403);
        $rule = DeviceAccessSetting::whereKey($rule->id)->where('device_id', $device->id)->where('kind', 'group')->firstOrFail();
        $period = DeviceAccessSetting::whereKey($rule->managed_period_id)->where('device_id', $device->id)->where('kind', 'period')->firstOrFail();
        abort_unless($rule->revision === $revision && $period->revision === $periodRevision, 409);
        $command = $this->settings->apply($device, $period, $periodRevision);

        return $this->settings->apply($device, $rule, $revision, $command->gateway_command_id);
    }
}
