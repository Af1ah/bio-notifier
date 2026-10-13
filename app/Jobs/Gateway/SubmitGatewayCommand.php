<?php

namespace App\Jobs\Gateway;

use App\Models\DeviceCommand;
use App\Models\GatewayCommandSubmission;
use App\Models\GatewayConnection;
use App\Models\GatewayDeviceBinding;
use App\Models\Organisation;
use App\Services\Devices\DeviceIntegration;
use App\Services\Devices\Gateway\GatewayClient;
use App\Services\Devices\Gateway\GatewayRequestException;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class SubmitGatewayCommand implements ShouldQueue
{
    use Queueable;

    public int $tries = 5;

    public int $timeout = 30;

    public function __construct(public string $submissionId) {}

    public function signalId(): string
    {
        return 'command:'.$this->submissionId;
    }

    public function backoff(): array
    {
        return [5, 30, 120, 300];
    }

    public function handle(GatewayClient $client): void
    {
        if (tenancy()->initialized) {
            tenancy()->end();
        }
        $token = (string) Str::uuid();
        $claimed = GatewayCommandSubmission::whereKey($this->submissionId)->where('attempts', '<', 5)
            ->where(fn ($q) => $q->whereIn('state', ['pending', 'retrying'])->orWhere(fn ($q) => $q->where('state', 'submitting')->where('leased_until', '<', now())))
            ->where(fn ($q) => $q->whereNull('next_attempt_at')->orWhere('next_attempt_at', '<=', now()))
            ->update(['state' => 'submitting', 'lease_token' => $token, 'leased_until' => now()->addSeconds(180), 'attempts' => DB::raw('attempts + 1')]);
        if (! $claimed) {
            return;
        }
        $submission = GatewayCommandSubmission::findOrFail($this->submissionId);
        try {
            $binding = GatewayDeviceBinding::findOrFail($submission->binding_id);
            $organisation = Organisation::findOrFail($submission->organisation_id);
            if ($organisation->device_integration !== DeviceIntegration::GATEWAY || ! $binding->enabled || $binding->organisation_id !== $organisation->id || $binding->version !== $submission->binding_version) {
                throw new GatewayRequestException(422);
            }
            if ($submission->expires_at->isPast()) {
                throw new GatewayRequestException(410);
            }
            $result = $client->call(GatewayConnection::findOrFail($binding->gateway_connection_id), 'POST', '/v1/commands', [
                'id' => $submission->id, 'serial' => $binding->serial, 'binding_version' => $binding->version,
                'operation' => $submission->operation, 'expires_at' => $submission->expires_at->utc()->toIso8601String(),
            ]);
            if (($result['id'] ?? null) !== $submission->id || ! isset($result['wire_id'], $result['state'])) {
                throw new \RuntimeException('Invalid command acceptance.');
            }
            GatewayCommandSubmission::whereKey($submission->id)->where('lease_token', $token)->update(['state' => 'submitted', 'lease_token' => null, 'leased_until' => null, 'error_code' => null]);
        } catch (\Throwable $exception) {
            $permanent = $exception instanceof GatewayRequestException && in_array($exception->status, [401, 403, 409, 410, 422], true);
            $state = $permanent || $submission->attempts >= 5 ? 'parked' : 'retrying';
            GatewayCommandSubmission::whereKey($submission->id)->where('lease_token', $token)->update([
                'state' => $state, 'lease_token' => null, 'leased_until' => null, 'error_code' => $permanent ? 'configuration_or_ownership' : 'submission_failed',
                'next_attempt_at' => now()->addSeconds([5, 30, 120, 300, 300][min($submission->attempts - 1, 4)]),
            ]);
            if ($state === 'parked') {
                tenancy()->initialize(Organisation::findOrFail($submission->organisation_id));
                DeviceCommand::whereKey($submission->projection_id)->where('status', 'pending')->update(['status' => $submission->expires_at->isPast() ? 'expired' : ($permanent ? 'failed' : 'outcome_unknown')]);
            }
            throw $exception;
        } finally {
            tenancy()->end();
        }
    }
}
