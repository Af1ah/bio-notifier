<?php

namespace App\Http\Controllers;

use App\Jobs\ProcessEbioWebhookJob;
use App\Models\Organisation;
use App\Models\WebhookReceipt;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Validator;

class EbioWebhookController extends Controller
{
    public function handle(Request $request, $token)
    {
        if (! is_string($token) || ! preg_match('/^[a-f0-9]{64}$/', $token)) {
            return response('Invalid Token', 403);
        }
        $organisation = Organisation::where('ebio_webhook_token', $token)->first();

        if (! $organisation) {
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

        if (! empty($logs)) {
            // Commit recoverable evidence before acknowledgement, independently
            // of whether the queue is available. Queue payload contains only IDs.
            if (WebhookReceipt::where('organisation_id', $organisation->id)->where('state', '!=', 'processed')->count() >= 1000) {
                return response('Ingestion backlog full; retry later', 503);
            }
            $receipt = WebhookReceipt::firstOrCreate(['organisation_id' => $organisation->id, 'payload_hash' => hash('sha256', json_encode($logs, JSON_THROW_ON_ERROR))], ['payload' => $logs]);
            if (! $receipt->wasRecentlyCreated) {
                return response('Success', 200, ['Content-Type' => 'text/plain; charset=UTF-8']);
            }
            try {
                ProcessEbioWebhookJob::dispatch($organisation, [], $receipt->id);
            } catch (\Throwable $exception) {
                $receipt->update(['last_error' => 'Queue dispatch failed; awaiting recovery.']);
            }
        }

        return response('Success', 200, ['Content-Type' => 'text/plain; charset=UTF-8']);
    }
}
