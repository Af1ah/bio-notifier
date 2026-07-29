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
        Log::info("eBioServer Webhook raw payload for {$organisation->name}: ", $payload);

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
            return response('Success');
        }

        // eBioServer might send a single object or an array of objects
        if (isset($logs['EmployeeCode'])) {
            $logs = [$logs];
        }

        foreach ($logs as $logData) {
            if (!isset($logData['EmployeeCode']) || !isset($logData['LogDate'])) {
                continue;
            }

            // Lookup or create device
            $device = null;
            if (isset($logData['SerialNumber'])) {
                $device = \App\Models\Device::firstOrCreate(
                    ['serial_number' => $logData['SerialNumber']],
                    ['name' => $logData['DeviceName'] ?? 'Unknown Device', 'status' => 'online']
                );
            }

            // Map Status (Direction)
            $status = 0; // Default Check In
            if (isset($logData['Direction'])) {
                $dir = strtolower($logData['Direction']);
                if (str_contains($dir, 'out')) {
                    $status = 1;
                }
            }

            // Map Verify Type
            $verifyType = 1; // Default Fingerprint
            if (isset($logData['VerificationType'])) {
                $vType = strtolower($logData['VerificationType']);
                if (str_contains($vType, 'face')) {
                    $verifyType = 15;
                } elseif (str_contains($vType, 'card')) {
                    $verifyType = 2;
                } elseif (str_contains($vType, 'password') || str_contains($vType, 'pin')) {
                    $verifyType = 0;
                }
            }

            // Save to tenant's AttendanceLog table
            AttendanceLog::updateOrCreate([
                'pin' => $logData['EmployeeCode'],
                'punched_at' => \Illuminate\Support\Carbon::parse($logData['LogDate']),
            ], [
                'device_id' => $device ? $device->id : 0,
                'status' => $status,
                'verify_type' => $verifyType,
                'work_code' => isset($logData['WorkCode']) ? (int) $logData['WorkCode'] : null,
                'raw_data' => $logData,
            ]);
        }

        // Return exact string "Success" as required by the eBioServer manual
        return response('Success')->header('Content-Type', 'text/plain');
    }
}
