<?php

namespace App\Services\Devices;

use App\Jobs\Gateway\SyncGatewayBinding;
use App\Models\Device;
use App\Models\GatewayConnection;
use App\Models\GatewayDeviceBinding;
use App\Models\Organisation;
use App\Services\Devices\Gateway\GatewayCommands;
use App\Services\Devices\Gateway\ProvisionGatewayBinding;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

final class TenantDeviceRegistration
{
    public function register(array $input): Device
    {
        $company = tenant();
        abort_unless($company instanceof Organisation && auth('web')->user()?->has_login, 403);
        abort_unless($company->fresh()->device_integration === DeviceIntegration::GATEWAY, 403);
        $data = Validator::make($input, [
            'serial_number' => ['required', 'string', 'regex:/^[A-Za-z0-9_-]{1,64}$/'],
            'name' => ['required', 'string', 'max:255'],
            'device_ip' => ['nullable', 'ip'],
            'location' => ['nullable', 'string', 'max:255'],
            'timezone' => ['required', 'timezone'],
            'direction' => ['sometimes', 'required', Rule::in(array_keys(Device::DIRECTION_OPTIONS))],
        ])->validate();
        $connections = GatewayConnection::where('enabled', true)->limit(2)->get();
        if ($connections->count() !== 1) {
            throw ValidationException::withMessages(['serial_number' => 'Device registration is unavailable. Contact your administrator.']);
        }
        $actorId = auth('web')->id();
        $central = DB::connection(config('tenancy.database.central_connection'));
        try {
            $binding = $central->transaction(function () use ($data, $company, $connections) {
                $company = Organisation::whereKey($company->id)->lockForUpdate()->firstOrFail();
                $existing = GatewayDeviceBinding::where('serial', $data['serial_number'])->first();
                if ($existing && $existing->organisation_id !== $company->id) {
                    throw ValidationException::withMessages(['serial_number' => 'This serial number is already registered. Contact your administrator if it needs to be moved.']);
                }
                // Kept for compatibility with the existing binding schema only.
                // Device admission uses registered ownership, not the office IP.
                $sourceCidr = '0.0.0.0/0';

                return app(ProvisionGatewayBinding::class)->provision([
                    'gateway_connection_id' => $connections->first()->id, 'organisation_id' => $company->id,
                    'serial' => $data['serial_number'], 'device_name' => $data['name'],
                    'source_cidr' => $sourceCidr,
                    'timezone' => $data['timezone'], 'enabled' => true, 'capabilities' => array_keys(GatewayCommands::OPERATIONS),
                ]);
            });
        } catch (UniqueConstraintViolationException $exception) {
            throw ValidationException::withMessages(['serial_number' => 'This serial number is already registered.']);
        } finally {
            // Provisioning touches central and tenant storage; restore the current
            // request's tenant before rendering its response or doing local work.
            if (! tenancy()->initialized || tenant('id') !== $company->id) {
                tenancy()->initialize($company);
            }
        }
        $device = Device::findOrFail($binding->device_id);
        $device->update(['ip_address' => $data['device_ip'] ?? $device->ip_address, 'name' => $data['name'], 'options' => array_merge($device->options ?? [], ['location' => $data['location'] ?? null, 'timezone' => $data['timezone'], 'direction' => $data['direction'] ?? 'DEVICE'])]);
        $central->table('gateway_audit_events')->insert([
            'action' => 'tenant_device_registered', 'subject' => $binding->serial,
            'metadata' => json_encode(['organisation_id' => $company->id, 'tenant_user_id' => $actorId]),
            'created_at' => now(), 'updated_at' => now(),
        ]);
        // The unsynced central binding is durable recovery work if this wake-up fails.
        try {
            SyncGatewayBinding::dispatch($binding->id)->onConnection('gateway')->onQueue('device-commands');
        } catch (\Throwable $exception) {
            report($exception);
        }

        return $device;
    }

    public function canRepair(Device $device): bool
    {
        if (! tenant() || ! auth('web')->user()?->has_login || tenant()->device_integration !== DeviceIntegration::GATEWAY) {
            return false;
        }
        $key = 'gateway-registration-status:'.tenant('id');
        // One central lookup per request, not one query per device menu.
        if (! request()->attributes->has($key)) {
            request()->attributes->set($key, GatewayDeviceBinding::where('organisation_id', tenant('id'))->where('enabled', true)
                ->get(['device_id', 'serial', 'synced_at', 'last_error'])->keyBy('device_id'));
        }
        $binding = request()->attributes->get($key)->get($device->id);

        return $binding && $binding->serial === $device->serial_number && ($binding->last_error || ! $binding->synced_at);
    }

    public function repair(Device $device): void
    {
        $company = tenant();
        abort_unless($company instanceof Organisation && auth('web')->user()?->has_login && $company->fresh()->device_integration === DeviceIntegration::GATEWAY, 403);
        $binding = GatewayDeviceBinding::where('organisation_id', $company->id)->where('device_id', $device->id)->where('serial', $device->serial_number)->where('enabled', true)->firstOrFail();
        abort_unless($binding->last_error || ! $binding->synced_at, 409);
        $binding->update(['last_error' => null, 'synced_at' => null]);
        request()->attributes->remove('gateway-registration-status:'.$company->id);
        DB::connection(config('tenancy.database.central_connection'))->table('gateway_audit_events')->insert([
            'action' => 'tenant_registration_retried', 'subject' => $binding->serial,
            'metadata' => json_encode(['organisation_id' => $company->id, 'tenant_user_id' => auth('web')->id()]), 'created_at' => now(), 'updated_at' => now(),
        ]);
        SyncGatewayBinding::dispatch($binding->id)->onConnection('gateway')->onQueue('device-commands');
    }

    public function setup(Device $device): array
    {
        abort_unless(tenant() && auth('web')->user()?->has_login, 403);
        $binding = GatewayDeviceBinding::where('organisation_id', tenant('id'))->where('device_id', $device->id)->where('serial', $device->serial_number)->first();
        $url = $binding ? GatewayConnection::find($binding->gateway_connection_id)?->device_server_url : null;
        $parts = $url ? parse_url($url) : [];

        return [
            'server' => $parts['host'] ?? null,
            'port' => $parts['port'] ?? (($parts['scheme'] ?? null) === 'https' ? 443 : 80),
            'secure' => ($parts['scheme'] ?? null) === 'https',
            'ready' => $binding?->synced_at !== null,
            'serial' => $device->serial_number,
        ];
    }
}
