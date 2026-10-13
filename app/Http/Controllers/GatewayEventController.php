<?php

namespace App\Http\Controllers;

use App\Jobs\Gateway\ApplyGatewayReceipt;
use App\Models\GatewayBindingVersion;
use App\Models\GatewayConnection;
use App\Models\GatewayDeviceBinding;
use App\Models\GatewayIngressReceipt;
use App\Models\Organisation;
use App\Services\Devices\DeviceIntegration;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\IpUtils;

final class GatewayEventController extends Controller
{
    public function __invoke(Request $request)
    {
        // This is a service API: session/domain tenancy must never choose its database.
        if (tenancy()->initialized) {
            tenancy()->end();
        }
        $token = $request->bearerToken();
        $connection = null;
        if (is_string($token) && strlen($token) >= 32) {
            foreach (GatewayConnection::where('enabled', true)->limit(20)->get() as $candidate) {
                if (strlen($candidate->events_token) >= 32 && hash_equals($candidate->events_token, $token)) {
                    $connection = $candidate;
                }
            }
        }
        abort_unless($connection, 401);
        abort_if(strlen($request->getContent()) > 1048576, 413);
        $data = $request->validate([
            'id' => ['required', 'uuid'],
            'serial' => ['required', 'regex:/^[A-Za-z0-9_-]{1,64}$/'],
            'binding_version' => ['required', 'integer', 'min:1'],
            'kind' => ['required', 'in:attendance,users,heartbeat,device_info,command_result'],
            'source_ip' => ['nullable', 'ip'],
            'received_at' => ['required', 'date'],
            'punches' => ['required_if:kind,attendance', 'array', 'min:1', 'max:1000'],
            'punches.*' => ['array:pin,at,status,verify,work_code'],
            'punches.*.pin' => ['required', 'string', 'max:64'],
            'punches.*.at' => ['required', 'date_format:Y-m-d\TH:i:sP'],
            'punches.*.status' => ['required', 'integer', 'between:0,255'],
            'punches.*.verify' => ['required', 'integer', 'between:0,255'],
            'punches.*.work_code' => ['nullable', 'integer', 'between:0,255'],
            'users' => ['exclude_unless:kind,users', 'present', 'array', 'max:1000'],
            'users.*' => ['array:pin,name,card'],
            'users.*.pin' => ['required', 'string', 'max:64'],
            'users.*.name' => ['nullable', 'string', 'max:255'],
            'users.*.card' => ['nullable', 'string', 'max:64'],
            'command_id' => ['required_if:kind,command_result', 'uuid'],
            'command_state' => ['required_if:kind,command_result', 'in:delivered,awaiting_data,acknowledged,failed,expired,outcome_unknown,cancelled'],
            'device_info' => ['exclude_unless:kind,device_info', 'required', 'array:firmware_version,model,device_type,push_version'],
            'device_info.*' => ['string', 'max:255'],
        ]);
        $hash = hash('sha256', $request->getContent());
        $receipt = $connection->getConnection()->transaction(function () use ($connection, $data, $hash) {
            GatewayConnection::whereKey($connection->id)->lockForUpdate()->firstOrFail();
            $existing = GatewayIngressReceipt::find($data['id']);
            if ($existing) {
                abort_unless($existing->gateway_connection_id === $connection->id && hash_equals($existing->payload_hash, $hash), 409);

                return $existing;
            }
            $binding = GatewayDeviceBinding::where('serial', $data['serial'])->lockForUpdate()->first();
            abort_unless($binding && $binding->enabled && $binding->gateway_connection_id === $connection->id, 422);
            $version = GatewayBindingVersion::where('binding_id', $binding->id)->where('version', $data['binding_version'])->whereNotNull('activated_at')->first();
            abort_unless($version && $version->organisation_id === $binding->organisation_id && $version->device_id === $binding->device_id && $version->gateway_connection_id === $connection->id, 422);
            $organisation = Organisation::find($binding->organisation_id);
            abort_unless($organisation && $organisation->device_integration === DeviceIntegration::GATEWAY, 422);
            if ($data['kind'] !== 'command_result') {
                abort_unless(isset($data['source_ip']) && IpUtils::checkIp($data['source_ip'], $version->source_cidr), 422);
            }
            abort_if(GatewayIngressReceipt::whereNotIn('state', ['applied'])->count() >= config('device_gateway.max_pending_receipts'), 503);

            return GatewayIngressReceipt::create([
                'id' => $data['id'], 'gateway_connection_id' => $connection->id,
                'binding_id' => $binding->id, 'organisation_id' => $binding->organisation_id,
                'device_id' => $binding->device_id, 'binding_version' => $version->version,
                'kind' => $data['kind'], 'payload_hash' => $hash, 'payload' => $data, 'state' => 'pending',
            ]);
        });
        // The receipt is the outbox; failure to wake a worker must not lose acceptance.
        try {
            ApplyGatewayReceipt::dispatch($receipt->id)->onConnection('gateway')->onQueue('device-ingest');
        } catch (\Throwable $e) {
            report($e);
        }

        return response()->json(['accepted' => true, 'id' => $receipt->id], 202);
    }
}
