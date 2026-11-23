<?php

namespace App\Services\Integrations;

use App\Models\AttendanceLog;
use App\Models\FrappeSetting;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;

final class FrappeCheckins
{
    public const LOCK = 'frappe-checkin-delivery';

    public const HOSTNAME_PATTERN = '/\A(?=.{1,253}\z)(?:[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?\.)+[a-z](?:[a-z0-9-]{0,61}[a-z0-9])?\z/i';

    public static function record(int ...$logIds): void
    {
        if ($logIds === [] || ! tenancy()->initialized || ! Schema::hasTable('frappe_settings')
            || ! FrappeSetting::whereKey(1)->where('enabled', true)->sharedLock()->first(['id'])) {
            return;
        }

        $readyAt = now();
        foreach (array_chunk($logIds, 400) as $batch) {
            DB::table('frappe_checkin_deliveries')->insert(array_map(
                fn (int $id): array => ['attendance_log_id' => $id, 'retry_at' => $readyAt],
                $batch,
            ));
        }
    }

    public static function payload(AttendanceLog $log): array
    {
        $payload = [
            'employee_field_value' => (string) $log->pin,
            'timestamp' => $log->punched_at->format('Y-m-d H:i:s.u'),
            'device_id' => (string) $log->device_id,
        ];

        $direction = match ($log->attendance_status) {
            0, 3, 4 => 'IN',
            1, 2, 5 => 'OUT',
            default => null,
        };

        $ebioWithoutDirection = isset($log->raw_data['EmployeeCode']) && blank($log->raw_data['Direction'] ?? null);
        if ($direction !== null && ! $ebioWithoutDirection) {
            $payload['log_type'] = $direction;
        }

        return $payload;
    }

    public function push(FrappeSetting $settings, AttendanceLog $log, ?string $address): FrappeDeliveryResult
    {
        $hostname = $settings->hostname;
        if (! preg_match(self::HOSTNAME_PATTERN, $hostname) || $address === null
            || ! self::isPublicIpv4($address) || ! extension_loaded('curl')) {
            return new FrappeDeliveryResult(error: 'destination', cooldownSeconds: 900);
        }

        $payload = self::payload($log);
        try {
            $response = Http::acceptJson()
                ->withHeaders(['Authorization' => 'token '.$settings->api_key.':'.$settings->api_secret])
                ->withoutRedirecting()
                ->connectTimeout(3)
                ->timeout(7)
                // Use the validated address for this connection while retaining HTTPS hostname verification.
                ->withOptions(['verify' => true, 'proxy' => '', 'curl' => [
                    CURLOPT_PROXY => '',
                    CURLOPT_IPRESOLVE => CURL_IPRESOLVE_V4,
                    CURLOPT_RESOLVE => [$hostname.':443:'.$address],
                ]])
                ->post('https://'.$hostname.'/api/method/hrms.hr.doctype.employee_checkin.employee_checkin.add_log_based_on_employee_field', $payload);
        } catch (ConnectionException) {
            return new FrappeDeliveryResult(error: 'connection', cooldownSeconds: 60);
        }

        if ($response->toPsrResponse()->getBody()->getSize() > 1048576) {
            return new FrappeDeliveryResult(error: 'unexpected_response');
        }

        if ($response->successful()) {
            $checkin = $response->json('message');
            $timestamp = $checkin['time'] ?? null;
            $device = $checkin['device_id'] ?? null;
            $matches = is_string($checkin['name'] ?? null) && filled($checkin['name'])
                && is_string($timestamp)
                && preg_match('/\A[0-9-]{10} [0-9:]{8}(?:\.[0-9]{1,6})?\z/', $timestamp)
                && substr($timestamp, 0, 19) === substr($payload['timestamp'], 0, 19)
                && (is_string($device) || is_int($device)) && (string) $device === $payload['device_id']
                && (! isset($payload['log_type']) || ($checkin['log_type'] ?? null) === $payload['log_type']);

            return $matches
                ? new FrappeDeliveryResult(outcome: 'verified')
                : new FrappeDeliveryResult(error: 'unexpected_response');
        }

        // Frappe validates uniqueness on Employee, timestamp and log type before insert.
        // The documented endpoint may return this validation error if the first response was lost.
        if ($response->status() === 417 && $response->json('exc_type') === 'ValidationError') {
            $encodedMessages = $response->json('_server_messages');
            $messages = is_string($encodedMessages) ? json_decode($encodedMessages, true) : null;
            foreach (is_array($messages) ? $messages : [] as $message) {
                $detail = is_string($message) ? json_decode($message, true) : null;
                if (is_string($detail['message'] ?? null)
                    && str_starts_with($detail['message'], 'This employee already has a log with the same timestamp.')) {
                    return new FrappeDeliveryResult(outcome: 'existing');
                }
            }
        }

        $status = $response->status();
        $cooldown = match (true) {
            $status === 429 => $this->retryAfter($response->header('Retry-After')),
            in_array($status, [401, 403, 404], true) => 900,
            $status >= 500 => 60,
            default => 0,
        };

        return new FrappeDeliveryResult(error: 'http_'.$status, cooldownSeconds: $cooldown);
    }

    private function retryAfter(?string $header): int
    {
        if ($header === null || strlen($header) > 64) {
            return 60;
        }

        $seconds = ctype_digit($header) ? (int) $header : (strtotime($header) ?: time()) - time();

        return max(60, min(3600, $seconds));
    }

    public function resolvePublicAddress(string $hostname): ?string
    {
        if (! preg_match(self::HOSTNAME_PATTERN, $hostname)) {
            return null;
        }

        $addresses = gethostbynamel($hostname.'.') ?: [];
        foreach ($addresses as $address) {
            if (! self::isPublicIpv4($address)) {
                return null;
            }
        }

        return $addresses[0] ?? null;
    }

    private static function isPublicIpv4(string $address): bool
    {
        return filter_var($address, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4 | FILTER_FLAG_GLOBAL_RANGE) !== false
            && (int) explode('.', $address, 2)[0] < 224;
    }
}
