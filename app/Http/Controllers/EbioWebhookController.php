<?php

namespace App\Http\Controllers;

use App\Models\Organisation;
use App\Models\AttendanceLog;
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
                return response('Success'); // Return success so eBio doesn't retry infinitely
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
                return response('Success');
            }

            // Clean up trailing invisible characters from decryption block padding if any
            $decrypted = trim($decrypted);
            
            $logs = json_decode($decrypted, true);
        } else {
            // Unencrypted JSON payload
            $logs = $payload;
        }

        if (! $logs) {
            $responsePayload = json_encode(['StatusCode' => '200', 'Message' => 'Success']);
            return response($responsePayload)
                ->header('Content-Type', 'application/json')
                ->header('Content-Length', (string) strlen($responsePayload));
        }

        // eBioServer might send a single object or an array of objects
        if (isset($logs['EmployeeCode'])) {
            $logs = [$logs];
        }

        if (!empty($logs)) {
            \App\Jobs\ProcessEbioWebhookJob::dispatch($organisation, $logs);
        }

        // eBioServer expects a specific JSON format to acknowledge the webhook
        $responsePayload = json_encode(['StatusCode' => '200', 'Message' => 'Success']);
        return response($responsePayload)
            ->header('Content-Type', 'application/json')
            ->header('Content-Length', (string) strlen($responsePayload));
    }
}
