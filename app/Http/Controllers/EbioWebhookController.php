<?php

namespace App\Http\Controllers;

use App\Jobs\ProcessEbioWebhookJob;
use App\Models\Organisation;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class EbioWebhookController extends Controller
{
    public function handle(Request $request, $token)
    {
        $organisation = Organisation::find($token);

        if (! $organisation) {
            return response('Invalid Token', 403);
        }

        // Initialize multi-db tenant context
        tenancy()->initialize($organisation);

        $payload = $request->all();
        Log::info("eBioServer Webhook received for {$organisation->name}.");

        // Check if data is encrypted (has 'data' key)
        if (isset($payload['data'])) {
            if (! $organisation->ebio_aes_password) {
                Log::error("eBioServer Webhook: Received encrypted payload but no AES password is set for {$organisation->name}");
                tenancy()->end();

                return response('Success', 200, ['Content-Type' => 'text/plain; charset=UTF-8']); // Prevent infinite device retries.
            }

            // Pad password with '1's up to 32 chars as per documentation
            $key = str_pad($organisation->ebio_aes_password, 32, '1');

            // Try to decrypt (Assuming 16 zero bytes IV which is standard for eSSL if not provided)
            $decrypted = openssl_decrypt(
                base64_decode($payload['data']),
                'aes-256-cbc',
                $key,
                OPENSSL_RAW_DATA,
                str_repeat("\0", 16)
            );

            if ($decrypted === false) {
                Log::error("eBioServer Webhook: Failed to decrypt payload for {$organisation->name}");
                tenancy()->end();

                return response('Success', 200, ['Content-Type' => 'text/plain; charset=UTF-8']);
            }

            // Clean up trailing invisible characters from decryption block padding if any
            $decrypted = trim($decrypted);

            $logs = json_decode($decrypted, true);
        } else {
            // Unencrypted JSON payload
            $logs = $payload;
        }

        if (! $logs) {
            tenancy()->end();

            return response('Success', 200, ['Content-Type' => 'text/plain; charset=UTF-8']);
        }

        // eBioServer might send a single object or an array of objects
        if (isset($logs['EmployeeCode'])) {
            $logs = [$logs];
        }

        if (! empty($logs)) {
            ProcessEbioWebhookJob::dispatch($organisation, $logs);
        }

        tenancy()->end();

        return response('Success', 200, ['Content-Type' => 'text/plain; charset=UTF-8']);
    }
}
