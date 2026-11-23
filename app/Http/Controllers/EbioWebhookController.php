<?php

namespace App\Http\Controllers;

use App\Jobs\ProcessEbioWebhookJob;
use App\Models\Device;
use App\Models\Organisation;
use App\Models\WebhookReceipt;
use App\Services\Devices\DeviceIntegration;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Validator;

class EbioWebhookController extends Controller
{
    public function desktop(Request $request)
    {
        $key = $request->header('X-API-Key');
        abort_unless(is_string($key) && preg_match('/^[a-f0-9]{64}$/', $key), 401);
        $organisation = Organisation::where('device_integration', DeviceIntegration::DESKTOP)
            ->where('ebio_webhook_token', $key)->first();
        abort_unless($organisation, 401);
        abort_if(strlen($request->getContent()) > 1048576, 413);
        abort_unless($request->isJson(), 415);

        $data = $request->validate([
            'id' => ['required', 'uuid'],
            'serial' => ['required', 'regex:/^[A-Za-z0-9_-]{1,64}$/'],
            'punches' => ['required', 'array', 'list', 'min:1', 'max:250'],
            'punches.*' => ['required', 'array:pin,at,status,verify,work_code'],
            'punches.*.pin' => ['required', 'string', 'max:100'],
            'punches.*.at' => ['required', 'date_format:Y-m-d\TH:i:sP'],
            'punches.*.status' => ['required', 'integer', 'between:0,255'],
            'punches.*.verify' => ['required', 'integer', 'between:0,255'],
            'punches.*.work_code' => ['nullable', 'integer'],
        ]);
        $known = $organisation->run(fn () => Device::where('serial_number', $data['serial'])->exists());
        abort_unless($known, 422, 'Register the device serial in Bio-Notifier before sending punches.');

        $logs = array_map(fn (array $punch): array => [
            'EmployeeCode' => $punch['pin'],
            'LogDate' => $punch['at'],
            'SerialNumber' => $data['serial'],
            'StatusCode' => (int) $punch['status'],
            'VerifyCode' => (int) $punch['verify'],
            'WorkCode' => $punch['work_code'] ?? null,
        ], $data['punches']);

        $id = strtolower($data['id']);
        $hash = hash('sha256', json_encode([$id, $logs], JSON_THROW_ON_ERROR));
        $receipt = $organisation->getConnection()->transaction(function () use ($organisation, $key, $id, $hash, $logs) {
            $current = Organisation::whereKey($organisation->id)->lockForUpdate()->first();
            abort_unless($current?->device_integration === DeviceIntegration::DESKTOP &&
                hash_equals((string) $current->ebio_webhook_token, $key), 401);
            $existing = WebhookReceipt::where(['organisation_id' => $organisation->id, 'desktop_batch_id' => $id])->first();
            if ($existing) {
                abort_unless(hash_equals($existing->payload_hash, $hash), 409);

                return $existing;
            }
            abort_if(WebhookReceipt::where('organisation_id', $organisation->id)->where('state', '!=', 'processed')->count() >= 1000, 503);

            return WebhookReceipt::create([
                'organisation_id' => $organisation->id, 'desktop_batch_id' => $id,
                'payload_hash' => $hash, 'payload' => $logs,
            ]);
        });
        $this->dispatchReceipt($organisation, $receipt);

        return response()->json(['accepted' => true, 'id' => $data['id']], 202);
    }

    public function handle(Request $request, $token)
    {
        if (! is_string($token) || ! preg_match('/^[a-f0-9]{64}$/', $token)) {
            return response('Invalid Token', 403);
        }
        $organisation = Organisation::where('ebio_webhook_token', $token)->first();

        if (! $organisation || ! DeviceIntegration::usesEbio($organisation)) {
            return response('Invalid Token', 403);
        }

        if (strlen($request->getContent()) > 1048576) {
            return response('Payload too large', 413);
        }

        $payload = $request->all();

        // Check if data is encrypted (has 'data' key)
        if (isset($payload['data'])) {
            if (! is_string($payload['data']) || ($encrypted = base64_decode($payload['data'], true)) === false) {
                return response('Invalid encrypted payload', 422);
            }
            if (! $organisation->ebio_aes_password) {
                Log::error("eBioServer Webhook: Received encrypted payload but no AES password is set for {$organisation->name}");

                return response('Encryption configuration missing', 422);
            }

            // Pad password with '1's up to 32 chars as per documentation
            $key = str_pad($organisation->ebio_aes_password, 32, '1');

            // Try to decrypt (Assuming 16 zero bytes IV which is standard for eSSL if not provided)
            $decrypted = openssl_decrypt(
                $encrypted,
                'aes-256-cbc',
                $key,
                OPENSSL_RAW_DATA,
                str_repeat("\0", 16)
            );

            if ($decrypted === false) {
                Log::error("eBioServer Webhook: Failed to decrypt payload for {$organisation->name}");

                return response('Invalid encrypted payload', 422);
            }

            // Clean up trailing invisible characters from decryption block padding if any
            $decrypted = trim($decrypted);

            $logs = json_decode($decrypted, true);
        } else {
            // Unencrypted JSON payload
            $logs = $payload;
        }

        if (! $logs) {
            return response('Invalid payload', 422);
        }

        // eBioServer might send a single object or an array of objects
        if (isset($logs['EmployeeCode'])) {
            $logs = [$logs];
        }

        if (! is_array($logs) || ! array_is_list($logs) || count($logs) > 1000) {
            return response('Invalid batch', 422);
        }
        Validator::make(['logs' => $logs], [
            'logs' => ['required', 'array', 'min:1', 'max:1000'],
            'logs.*' => ['required', 'array'],
            'logs.*.EmployeeCode' => ['required', 'string', 'max:100'],
            'logs.*.LogDate' => ['required', 'date'],
            'logs.*.SerialNumber' => ['nullable', 'string', 'max:255'],
            'logs.*.DeviceName' => ['nullable', 'string', 'max:255'],
            'logs.*.Direction' => ['nullable', 'string', 'max:100'],
            'logs.*.VerificationType' => ['nullable', 'string', 'max:100'],
            'logs.*.WorkCode' => ['nullable', 'integer'],
        ])->validate();

        if (! $this->record($organisation, $logs)) {
            return response('Ingestion backlog full; retry later', 503);
        }

        return response('Success', 200, ['Content-Type' => 'text/plain; charset=UTF-8']);
    }

    private function record(Organisation $organisation, array $logs): bool
    {
        $key = ['organisation_id' => $organisation->id, 'payload_hash' => hash('sha256', json_encode($logs, JSON_THROW_ON_ERROR))];
        if (WebhookReceipt::where($key)->exists()) {
            return true;
        }
        if (WebhookReceipt::where('organisation_id', $organisation->id)->where('state', '!=', 'processed')->count() >= 1000) {
            return false;
        }

        $receipt = WebhookReceipt::firstOrCreate($key, ['payload' => $logs]);
        $this->dispatchReceipt($organisation, $receipt);

        return true;
    }

    private function dispatchReceipt(Organisation $organisation, WebhookReceipt $receipt): void
    {
        if ($receipt->wasRecentlyCreated) {
            try {
                ProcessEbioWebhookJob::dispatch($organisation, [], $receipt->id);
            } catch (\Throwable $exception) {
                $receipt->update(['last_error' => 'Queue dispatch failed; awaiting recovery.']);
            }
        }
    }
}
