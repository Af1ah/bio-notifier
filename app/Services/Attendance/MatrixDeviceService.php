<?php

namespace App\Services\Attendance;

use App\Models\Device;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class MatrixDeviceService
{
    /**
     * Convert the 24-bit decimal RFID value shown by the local reader into
     * the decimal card number used by Matrix VEGA/ARGO devices.
     *
     * Matrix stores this reader format as the hexadecimal prefix 5A00
     * followed by the three-byte RFID value. For example, 0001843877 becomes
     * 5A001C22A5 (386548900517).
     */
    public static function convert24BitRawRfid(string $rawRfid): string
    {
        $rawRfid = trim($rawRfid);

        if (! preg_match('/^\d{1,10}$/', $rawRfid)) {
            throw new \InvalidArgumentException('Raw RFID must contain digits only.');
        }

        $rawValue = (int) ltrim($rawRfid, '0');
        if ($rawValue < 1 || $rawValue > 0xFFFFFF) {
            throw new \InvalidArgumentException('Raw RFID must be a 24-bit value (1 to 16777215).');
        }

        return (string) ((0x5A00 << 24) + $rawValue);
    }

    /**
     * Map of Matrix API Response Codes to human-readable explanations
     * according to the Matrix COSEC Devices API User Guide (Table: API Response Codes).
     */
    public const RESPONSE_CODES = [
        0 => 'Successful',
        1 => 'Failed - Invalid Login Credentials',
        2 => 'Date and time – manual set failed',
        3 => 'Invalid Date/Time',
        4 => 'Maximum users are already configured on device',
        5 => 'Image – size is too big',
        6 => 'Image – format not supported',
        7 => 'Card 1 and card 2 are identical',
        8 => 'Card ID already exists on device',
        9 => 'Fingerprint or Palm template already exists',
        10 => 'No Record Found',
        11 => 'Template size or format mismatch',
        12 => 'Fingerprint memory full on device',
        13 => 'User ID not found on device',
        14 => 'Credential limit reached on device',
        15 => 'Reader mismatch or Reader not configured',
        16 => 'Device Busy (Another enrollment or menu is currently active on device)',
        17 => 'Internal device process error',
        18 => 'PIN already exists on device',
        19 => 'Biometric credential not found on device',
        20 => 'Memory Card not found',
        21 => 'Reference User ID already exists',
        22 => 'Wrong Selection (count exceeds maximum available places)',
    ];

    /**
     * Test connection and retrieve basic configuration from a Matrix COSEC device.
     */
    public function checkConnection(Device $device): array
    {
        if (empty($device->ip_address)) {
            return [
                'success' => false,
                'message' => 'Device IP address is not configured.',
                'status' => 'offline',
            ];
        }

        try { $baseUrl = $this->buildBaseUrl($device); }
        catch (\RuntimeException $e) { return ['success'=>false,'message'=>$e->getMessage(),'code'=>null,'status'=>'offline']; }
        $auth = [$device->username ?? 'admin', $device->password ?? '1234'];

        try {
            $response = Http::withDigestAuth(...$auth)
                ->timeout(5)
                ->get("{$baseUrl}/device.cgi/device-basic-config", [
                    'action' => 'get',
                    'format' => 'xml',
                ]);

            if ($response->status() === 401) {
                return [
                    'success' => false,
                    'message' => 'Authentication failed: Invalid username or password (HTTP 401).',
                    'status' => 'offline',
                ];
            }

            if (!$response->successful()) {
                return [
                    'success' => false,
                    'message' => "Device returned HTTP error: {$response->status()}",
                    'status' => 'offline',
                ];
            }

            $body = $response->body();
            $parsed = $this->parseResponse($body, $response->status());

            if (!$parsed['success']) {
                return [
                    'success' => false,
                    'message' => $parsed['message'],
                    'status' => 'offline',
                ];
            }

            $xml = @simplexml_load_string($body);
            $deviceName = trim((string) ($xml->name ?? ''));
            $model = !empty($deviceName) ? "Matrix COSEC ({$deviceName})" : 'Matrix COSEC';

            // Detect supported enrollment methods from device capabilities
            $methods = ['card'];
            if (isset($xml->{'max-faces'}) && (int) $xml->{'max-faces'} > 0) {
                $methods[] = 'face';
            }
            if (isset($xml->{'max-fingers'}) && (int) $xml->{'max-fingers'} > 0) {
                $methods[] = 'finger';
            }
            $methods[] = 'special_card';

            return [
                'success' => true,
                'message' => "Device is online ({$model})",
                'status' => 'online',
                'model' => $model,
                'device_name' => $deviceName,
                'enrollment_methods' => array_values(array_unique($methods)),
            ];
        } catch (\Illuminate\Http\Client\ConnectionException $e) {
            return [
                'outcome_unknown' => true,
                'success' => false,
                'message' => "Cannot connect to Matrix device at {$device->ip_address}:" . ($device->port ?? 80) . " (Connection timed out or host unreachable).",
                'status' => 'offline',
            ];
        } catch (\Exception $e) {
            return [
                'outcome_unknown' => true,
                'success' => false,
                'message' => 'Connection error: ' . $e->getMessage(),
                'status' => 'offline',
            ];
        }
    }

    /**
     * Add or update a user on a Matrix COSEC device.
     * Note: In Matrix COSEC CGI API, 'user-group' is NOT supported on many door firmware versions
     * and causes 'Request Failed: Invalid Command "user-group=1"'.
     * 'ref-user-id' must be strictly numeric (up to 8 digits).
     */
    public function setUser(Device $device, array $userData): array
    {
        // The background sync path calls this value a PIN, while the Matrix
        // device-control form correctly calls it a User ID. They represent the
        // same Matrix user-id parameter.
        $pin = (string) ($userData['pin'] ?? $userData['user_id'] ?? '');
        if (empty($pin)) {
            return [
                'success' => false,
                'message' => 'Matrix User ID is required for device configuration.',
                'code' => null,
            ];
        }

        try { $baseUrl = $this->buildBaseUrl($device); }
        catch (\RuntimeException $e) { return ['success'=>false,'message'=>$e->getMessage(),'code'=>null,'status'=>'offline']; }
        $auth = [$device->username ?? 'admin', $device->password ?? '1234'];

        // Clean user name: Matrix only accepts alphanumeric and spaces, max 15 characters
        $rawName = $userData['name'] ?? "User {$pin}";
        $cleanName = substr(trim(preg_replace('/[^a-zA-Z0-9 ]+/', ' ', $rawName)), 0, 15);
        if (empty($cleanName)) {
            $cleanName = "User {$pin}";
        }

        // ref-user-id must be strictly numeric and maximum 8 digits (1 to 99999999).
        // Existing callers can omit it, but the device-control form may supply a
        // distinct reference ID that must be retained.
        $providedRefUserId = $userData['ref_user_id'] ?? $userData['reference_id'] ?? null;
        if (filled($providedRefUserId)) {
            $refUserId = (string) $providedRefUserId;
            if (! preg_match('/^\d{1,8}$/', $refUserId)) {
                return [
                    'success' => false,
                    'message' => 'Reference ID must contain 1 to 8 digits.',
                    'code' => null,
                ];
            }
        } else {
            $numericPin = preg_replace('/[^0-9]/', '', $pin);
            if (! empty($numericPin)) {
                $refUserId = substr($numericPin, 0, 8);
            } elseif (isset($userData['user_id']) && is_numeric($userData['user_id'])) {
                $refUserId = (string) (((int) $userData['user_id']) % 100000000);
            } else {
                $refUserId = '1';
            }
        }

        $params = [
            'action' => 'set',
            'user-id' => substr($pin, 0, 10),
            'ref-user-id' => $refUserId,
            'name' => $cleanName,
            'user-active' => array_key_exists('user_active', $userData) && ! $userData['user_active'] ? 0 : 1,
            'enable-fr' => 1,
            'format' => 'xml',
        ];

        if (array_key_exists('vip', $userData)) {
            $params['vip'] = $userData['vip'] ? 1 : 0;
        }

        // Optional PIN (1 to 6 digits)
        if (!empty($userData['password'])) {
            $numericPass = preg_replace('/[^0-9]/', '', (string) $userData['password']);
            if (!empty($numericPass)) {
                $params['user-pin'] = substr($numericPass, 0, 6);
            }
        }

        // Card fields can be cleared explicitly by passing an empty value. Matrix
        // uses 0 to remove the respective card assignment.
        foreach (['card1' => 'card1', 'card2' => 'card2', 'card' => 'card1'] as $input => $parameter) {
            if (! array_key_exists($input, $userData)) {
                continue;
            }

            $card = trim((string) $userData[$input]);
            if ($card === '') {
                $params[$parameter] = '0';
                continue;
            }

            if (! preg_match('/^\d{1,20}$/', $card)) {
                return [
                    'success' => false,
                    'message' => ucfirst($parameter) . ' must contain up to 20 digits.',
                    'code' => null,
                ];
            }

            $params[$parameter] = $card;
        }

        try {
            $response = Http::withDigestAuth(...$auth)
                ->timeout(10)
                ->get("{$baseUrl}/device.cgi/users", $params);

            $body = $response->body();
            // If device doesn't support enable-fr, gracefully retry without it
            if (stripos($body, 'enable-fr') !== false && stripos($body, 'Invalid Command') !== false) {
                unset($params['enable-fr']);
                $response = Http::withDigestAuth(...$auth)
                    ->timeout(10)
                    ->get("{$baseUrl}/device.cgi/users", $params);
            }

            return $this->parseResponse($response->body(), $response->status(), 'User successfully saved on Matrix device.');
        } catch (\Exception $e) {
            return [
                'outcome_unknown' => true,
                'success' => false,
                'message' => 'Matrix HTTP request error: ' . $e->getMessage(),
                'code' => null,
            ];
        }
    }

    /**
     * Retrieve one user's editable configuration from a Matrix COSEC device.
     */
    public function getUser(Device $device, string $userId): array
    {
        $userId = trim($userId);
        if ($userId === '') {
            return ['success' => false, 'message' => 'User ID is required.', 'user' => null];
        }

        try { $baseUrl = $this->buildBaseUrl($device); }
        catch (\RuntimeException $e) { return ['success'=>false,'message'=>$e->getMessage(),'code'=>null,'status'=>'offline']; }
        $auth = [$device->username ?? 'admin', $device->password ?? '1234'];

        try {
            $response = Http::withDigestAuth(...$auth)
                ->timeout(10)
                ->get("{$baseUrl}/device.cgi/users", [
                    'action' => 'get',
                    'user-id' => $userId,
                    'format' => 'xml',
                ]);

            $parsed = $this->parseResponse($response->body(), $response->status(), 'User retrieved.');
            if (! $parsed['success']) {
                return $parsed + ['user' => null];
            }

            $xml = @simplexml_load_string($response->body());
            if (! $xml) {
                return ['success' => false, 'message' => 'Matrix device returned invalid user data.', 'user' => null];
            }

            return [
                'success' => true,
                'message' => 'User retrieved.',
                'user' => [
                    'user_id' => (string) ($xml->{'user-id'} ?? $userId),
                    'reference_id' => (string) ($xml->{'ref-user-id'} ?? ''),
                    'name' => (string) ($xml->name ?? ''),
                    'user_active' => (string) ($xml->{'user-active'} ?? '0') === '1',
                    'vip' => (string) ($xml->vip ?? '0') === '1',
                    'card1' => $this->cardValueFromResponse($xml->card1 ?? null),
                    'card2' => $this->cardValueFromResponse($xml->card2 ?? null),
                ],
            ];
        } catch (\Exception $e) {
            return [
                'outcome_unknown' => true,'success' => false, 'message' => 'Get user error: ' . $e->getMessage(), 'user' => null];
        }
    }

    /**
     * Enable enrollment on the Matrix COSEC device (enroll-options?action=set&enroll-on-device=1).
     */
    public function enableDeviceEnrollment(Device $device, int $enrollMode = 0): array
    {
        try { $baseUrl = $this->buildBaseUrl($device); }
        catch (\RuntimeException $e) { return ['success'=>false,'message'=>$e->getMessage(),'code'=>null,'status'=>'offline']; }
        $auth = [$device->username ?? 'admin', $device->password ?? '1234'];

        try {
            $response = Http::withDigestAuth(...$auth)
                ->timeout(5)
                ->get("{$baseUrl}/device.cgi/enroll-options", [
                    'action' => 'set',
                    'enroll-on-device' => 1,
                    'format' => 'xml',
                ]);

            return $this->parseResponse($response->body(), $response->status(), 'Device enrollment enabled successfully (enroll-on-device=1).');
        } catch (\Exception $e) {
            return [
                'outcome_unknown' => true,
                'success' => false,
                'message' => 'Failed to enable device enrollment: ' . $e->getMessage(),
                'code' => null,
            ];
        }
    }

    /**
     * Delete a user from a Matrix COSEC device.
     */
    public function deleteUser(Device $device, string $pin): array
    {
        try { $baseUrl = $this->buildBaseUrl($device); }
        catch (\RuntimeException $e) { return ['success'=>false,'message'=>$e->getMessage(),'code'=>null,'status'=>'offline']; }
        $auth = [$device->username ?? 'admin', $device->password ?? '1234'];

        try {
            $response = Http::withDigestAuth(...$auth)
                ->timeout(10)
                ->get("{$baseUrl}/device.cgi/users", [
                    'action' => 'delete',
                    'user-id' => substr($pin, 0, 10),
                    'format' => 'xml',
                ]);

            $parsed = $this->parseResponse($response->body(), $response->status(), 'User deleted from Matrix device successfully.');
            
            // Response Code 13 means user was not found on device, which effectively means already deleted
            if ($parsed['code'] === 13) {
                return [
                    'success' => true,
                    'message' => 'User already does not exist on Matrix device.',
                    'code' => 13,
                ];
            }

            return $parsed;
        } catch (\Exception $e) {
            return [
                'outcome_unknown' => true,
                'success' => false,
                'message' => 'Matrix HTTP request error: ' . $e->getMessage(),
                'code' => null,
            ];
        }
    }

    /**
     * Initiate on-device biometric or card capture on a Matrix COSEC device.
     */
    public function enrollBiometric(Device $device, string $pin, string $type, array $extra = []): array
    {
        // 1. Ensure device enrollment is enabled on the device
        $this->enableDeviceEnrollment($device);

        // 2. Ensure user profile exists on the device with enable-fr=1 and user-active=1
        $userSync = $this->setUser($device, [
            'pin' => $pin,
            'name' => $extra['name'] ?? null,
            'user_id' => $extra['user_id'] ?? null,
        ]);

        if (!$userSync['success']) {
            return [
                'success' => false,
                'message' => "Failed to prepare user on device: {$userSync['message']}",
                'code' => $userSync['code'],
            ];
        }

        try { $baseUrl = $this->buildBaseUrl($device); }
        catch (\RuntimeException $e) { return ['success'=>false,'message'=>$e->getMessage(),'code'=>null,'status'=>'offline']; }
        $auth = [$device->username ?? 'admin', $device->password ?? '1234'];

        try {
            if ($type === 'special_card') {
                $spFnId = (int) ($extra['sp_fn_id'] ?? 18);
                $response = Http::withDigestAuth(...$auth)
                    ->timeout(10)
                    ->get("{$baseUrl}/device.cgi/enrollspcard", [
                        'action' => 'enroll',
                        'sp-fn-id' => $spFnId,
                        'card-count' => 0,
                        'format' => 'xml',
                    ]);
            } else {
                // 0 = Card, 1 = Smart Card, 2 = Finger, 7 = Face
                $matrixType = match ($type) {
                    'card' => 0,
                    'smart_card' => 1,
                    'finger' => 2,
                    'face' => 7,
                    default => 7,
                };

                $response = Http::withDigestAuth(...$auth)
                    ->timeout(10)
                    ->get("{$baseUrl}/device.cgi/enrolluser", [
                        'action' => 'enroll',
                        'user-id' => substr($pin, 0, 10),
                        'type' => $matrixType,
                        'format' => 'xml',
                    ]);
            }

            $typeLabel = match ($type) {
                'face' => 'Face',
                'finger' => 'Fingerprint',
                'card' => 'RFID Card',
                'special_card' => 'Special Function Card',
                default => ucfirst($type),
            };

            return $this->parseResponse(
                $response->body(),
                $response->status(),
                "{$typeLabel} enrollment initiated on {$device->name}. Please proceed on the device screen."
            );
        } catch (\Exception $e) {
            return [
                'outcome_unknown' => true,
                'success' => false,
                'message' => 'Matrix enrollment error: ' . $e->getMessage(),
                'code' => null,
            ];
        }
    }

    /**
     * Send door commands: unlockdoor, lockdoor, normalizedoor.
     */
    public function sendDoorCommand(Device $device, string $action): array
    {
        $validActions = ['unlockdoor', 'lockdoor', 'normalizedoor'];
        if (!in_array($action, $validActions)) {
            return ['success' => false, 'message' => "Invalid door action: {$action}", 'code' => null];
        }

        try { $baseUrl = $this->buildBaseUrl($device); }
        catch (\RuntimeException $e) { return ['success'=>false,'message'=>$e->getMessage(),'code'=>null,'status'=>'offline']; }
        $auth = [$device->username ?? 'admin', $device->password ?? '1234'];

        try {
            $response = Http::withDigestAuth(...$auth)
                ->timeout(10)
                ->get("{$baseUrl}/device.cgi/command", [
                    'action' => $action,
                    'format' => 'xml',
                ]);

            $successMsg = match ($action) {
                'unlockdoor' => 'Door Unlocked successfully.',
                'lockdoor' => 'Door Locked successfully.',
                'normalizedoor' => 'Door returned to normal state.',
            };

            return $this->parseResponse($response->body(), $response->status(), $successMsg);
        } catch (\Exception $e) {
            return [
                'outcome_unknown' => true,'success' => false, 'message' => 'Door command error: ' . $e->getMessage(), 'code' => null];
        }
    }

    /**
     * Sync time to Matrix device.
     */
    public function syncTime(Device $device, ?\Carbon\Carbon $dateTime = null): array
    {
        $dateTime = $dateTime ?? now();
        try { $baseUrl = $this->buildBaseUrl($device); }
        catch (\RuntimeException $e) { return ['success'=>false,'message'=>$e->getMessage(),'code'=>null,'status'=>'offline']; }
        $auth = [$device->username ?? 'admin', $device->password ?? '1234'];

        try {
            $response = Http::withDigestAuth(...$auth)
                ->timeout(10)
                ->get("{$baseUrl}/device.cgi/date-time", [
                    'action' => 'set',
                    'date' => $dateTime->format('d'),
                    'month' => $dateTime->format('m'),
                    'year' => $dateTime->format('Y'),
                    'hour' => $dateTime->format('H'),
                    'minute' => $dateTime->format('i'),
                    'second' => $dateTime->format('s'),
                    'format' => 'xml',
                ]);

            return $this->parseResponse($response->body(), $response->status(), 'Device time synchronized successfully.');
        } catch (\Exception $e) {
            return [
                'outcome_unknown' => true,'success' => false, 'message' => 'Time sync error: ' . $e->getMessage(), 'code' => null];
        }
    }

    /**
     * Get enrolled user count from Matrix device.
     */
    public function getUserCount(Device $device): array
    {
        try { $baseUrl = $this->buildBaseUrl($device); }
        catch (\RuntimeException $e) { return ['success'=>false,'message'=>$e->getMessage(),'code'=>null,'status'=>'offline']; }
        $auth = [$device->username ?? 'admin', $device->password ?? '1234'];

        try {
            $response = Http::withDigestAuth(...$auth)
                ->timeout(10)
                ->get("{$baseUrl}/device.cgi/command", [
                    'action' => 'getusercount',
                    'format' => 'xml',
                ]);

            $body = $response->body();
            if (preg_match('/<Enrolled-User-Count>(\d+)<\/Enrolled-User-Count>/i', $body, $matches)) {
                return [
                    'success' => true,
                    'count' => (int) $matches[1],
                    'message' => "Enrolled users on device: {$matches[1]}",
                ];
            }

            return $this->parseResponse($body, $response->status(), 'User count retrieved.');
        } catch (\Exception $e) {
            return [
                'outcome_unknown' => true,'success' => false, 'message' => 'Get user count error: ' . $e->getMessage(), 'count' => null];
        }
    }

    /**
     * Get the current event sequence and rollover counters from a Matrix device.
     */
    public function getEventCount(Device $device): array
    {
        try { $baseUrl = $this->buildBaseUrl($device); }
        catch (\RuntimeException $e) { return ['success'=>false,'message'=>$e->getMessage(),'code'=>null,'status'=>'offline']; }
        $auth = [$device->username ?? 'admin', $device->password ?? '1234'];

        try {
            $response = Http::withDigestAuth(...$auth)
                ->timeout(10)
                ->get("{$baseUrl}/device.cgi/command", [
                    'action' => 'geteventcount',
                    'format' => 'xml',
                ]);

            $body = $response->body();
            $parsed = $this->parseResponse($body, $response->status(), 'Event count retrieved.');

            if (! $parsed['success']) {
                return $parsed + ['sequence' => null, 'rollover' => null];
            }

            $xml = @simplexml_load_string($body);
            $sequence = $xml ? (int) ($xml->{'seq-number'} ?? $xml->{'Seq-Number'} ?? 0) : 0;
            $rollover = $xml ? (int) ($xml->{'roll-over-count'} ?? $xml->{'Roll-Over-Count'} ?? 0) : 0;

            if ($sequence < 1) {
                return [
                    'success' => false,
                    'message' => 'Matrix device returned an invalid event sequence number.',
                    'code' => null,
                    'sequence' => null,
                    'rollover' => null,
                ];
            }

            return [
                'success' => true,
                'message' => 'Event count retrieved.',
                'code' => $parsed['code'],
                'sequence' => $sequence,
                'rollover' => $rollover,
            ];
        } catch (\Exception $e) {
            return [
                'outcome_unknown' => true,
                'success' => false,
                'message' => 'Get event count error: ' . $e->getMessage(),
                'code' => null,
                'sequence' => null,
                'rollover' => null,
            ];
        }
    }

    /**
     * Parse Matrix HTTP response body and status code into a clear, friendly array.
     */
    public function parseResponse(string $body, int $httpStatus, string $defaultSuccessMessage = 'Command executed successfully.'): array
    {
        if ($httpStatus === 401) {
            return [
                'success' => false,
                'message' => 'Authentication failed: Invalid username or password (HTTP 401).',
                'code' => 1,
            ];
        }

        if ($httpStatus >= 400) {
            return [
                'success' => false,
                'message' => "Matrix device HTTP error {$httpStatus}.",
                'code' => null,
            ];
        }

        // Check for Response-Code tag
        if (preg_match('/<Response-Code>(\d+)<\/Response-Code>/i', $body, $matches)) {
            $code = (int) $matches[1];
            if ($code === 0) {
                return [
                    'success' => true,
                    'message' => $defaultSuccessMessage,
                    'code' => 0,
                ];
            }

            $description = self::RESPONSE_CODES[$code] ?? "Matrix device error code {$code}";
            return [
                'success' => false,
                'message' => "Device Error: {$description} (Code {$code})",
                'code' => $code,
            ];
        }

        // Check for "Request Failed: ..."
        if (stripos($body, 'Request Failed') !== false) {
            $cleanError = trim(strip_tags($body));
            return [
                'success' => false,
                'message' => $cleanError,
                'code' => null,
            ];
        }

        // Fallback check: if XML is valid without error tags
        if (stripos($body, '<COSEC_API>') !== false) {
            return [
                'success' => true,
                'message' => $defaultSuccessMessage,
                'code' => 0,
            ];
        }

        return [
            'success' => true,
            'message' => $defaultSuccessMessage,
            'code' => 0,
        ];
    }

    /**
     * Get Reader Configuration from Matrix device.
     */
    public function getReaderConfig(Device $device): array
    {
        try { $baseUrl = $this->buildBaseUrl($device); }
        catch (\RuntimeException $e) { return ['success'=>false,'message'=>$e->getMessage(),'code'=>null,'status'=>'offline']; }
        $auth = [$device->username ?? 'admin', $device->password ?? '1234'];

        try {
            $response = Http::withDigestAuth(...$auth)
                ->timeout(5)
                ->get("{$baseUrl}/device.cgi/reader-config", [
                    'action' => 'get',
                    'format' => 'xml',
                ]);

            if ($response->status() === 401) {
                return ['success' => false, 'message' => 'Authentication failed (401)', 'data' => []];
            }

            if (!$response->successful()) {
                return ['success'=>false,'message'=>'Device returned HTTP '.$response->status(),'data'=>[]];
            }
            $body = $response->body();
            $xml = @simplexml_load_string($body);
            if ($xml) {
                return [
                    'success' => true,
                    'message' => 'Reader configuration retrieved.',
                    'data' => [
                        'reader1' => (string) ($xml->reader1 ?? ''),
                        'reader2' => (string) ($xml->reader2 ?? ''),
                        'reader3' => (string) ($xml->reader3 ?? ''),
                        'door_access_mode' => (string) ($xml->{'door-access-mode'} ?? ''),
                        'door_entry_exit_mode' => (string) ($xml->{'door-entry-exit-mode'} ?? ''),
                        'reader_access_mode' => (string) ($xml->{'reader-access-mode'} ?? ''),
                        'reader_entry_exit_mode' => (string) ($xml->{'reader-entry-exit-mode'} ?? ''),
                        'tag_re_detect_delay' => (string) ($xml->{'tag-re-detect-delay'} ?? ''),
                    ],
                ];
            }

            return ['success' => false, 'message' => 'Failed to parse reader config XML', 'data' => []];
        } catch (\Exception $e) {
            return [
                'outcome_unknown' => true,'success' => false, 'message' => $e->getMessage(), 'data' => []];
        }
    }

    public function setInternalCardReader(Device $device, int $type): array
    {
        if ($device->vendor !== 'matrix' || !in_array($type, [0,1,2,3,4,5], true)) {
            throw \Illuminate\Validation\ValidationException::withMessages(['reader1'=>'Select a supported Matrix internal card reader type.']);
        }
        // Only reader1 is sent: preserve biometric, external and door policy settings.
        $result = $this->setReaderConfig($device, ['reader1'=>$type]);
        if (!$result['success']) {
            if (!empty($result['outcome_unknown'])) $result['message']='The connection was interrupted. Reopen reader settings to check the saved type before trying again.';
            return $result;
        }
        $readback = $this->getReaderConfig($device);
        if (!$readback['success'] || ($readback['data']['reader1'] ?? '') !== (string) $type) {
            return ['success'=>false,'outcome_unknown'=>true,'message'=>'The device accepted the update, but its saved type could not be confirmed. Reopen reader settings before trying again.'];
        }
        return ['success'=>true,'message'=>'Internal card reader type saved and confirmed.'];
    }

    /**
     * Set Reader Configuration on Matrix device (e.g. enable external exit reader).
     */
    public function setReaderConfig(Device $device, array $params = []): array
    {
        try { $baseUrl = $this->buildBaseUrl($device); }
        catch (\RuntimeException $e) { return ['success'=>false,'message'=>$e->getMessage(),'code'=>null,'status'=>'offline']; }
        $auth = [$device->username ?? 'admin', $device->password ?? '1234'];

        $queryParams = array_merge([
            'action' => 'set',
            'format' => 'xml',
        ], $params);

        try {
            $response = Http::withDigestAuth(...$auth)
                ->timeout(5)
                ->get("{$baseUrl}/device.cgi/reader-config", $queryParams);

            return $this->parseResponse($response->body(), $response->status(), 'Reader configuration updated successfully.');
        } catch (\Exception $e) {
            return [
                'outcome_unknown' => true,'success' => false, 'message' => 'Failed to update reader config: ' . $e->getMessage(), 'code' => null];
        }
    }

    /**
     * Fetch event logs from Matrix device.
     */
    public function getEventLogs(Device $device, int $seqNumber = 1, int $count = 20, int $rollOver = 0): array
    {
        try { $baseUrl = $this->buildBaseUrl($device); }
        catch (\RuntimeException $e) { return ['success'=>false,'message'=>$e->getMessage(),'code'=>null,'status'=>'offline']; }
        $auth = [$device->username ?? 'admin', $device->password ?? '1234'];

        try {
            $response = Http::withDigestAuth(...$auth)
                ->timeout(10)
                ->get("{$baseUrl}/device.cgi/events", [
                    'action' => 'getevent',
                    'roll-over-count' => $rollOver,
                    'seq-number' => $seqNumber,
                    'no-of-events' => $count,
                    'format' => 'xml',
                ]);

            if ($response->status() === 401) {
                return ['success' => false, 'message' => 'Authentication failed (401)', 'events' => []];
            }

            $body = $response->body();
            $xml = @simplexml_load_string($body);
            $events = [];
            if ($xml && isset($xml->Events)) {
                foreach ($xml->Events as $ev) {
                    $events[] = [
                        'seq' => (string) $ev->{'seq-No'},
                        'date' => (string) $ev->date,
                        'time' => (string) $ev->time,
                        'event_id' => (string) $ev->{'event-id'},
                        'detail_1' => (string) $ev->{'detail-1'},
                        'detail_2' => (string) $ev->{'detail-2'},
                        'detail_3' => (string) $ev->{'detail-3'},
                    ];
                }
            }

            return ['success' => true, 'events' => $events, 'raw' => $body];
        } catch (\Exception $e) {
            return [
                'outcome_unknown' => true,'success' => false, 'message' => $e->getMessage(), 'events' => []];
        }
    }

    /**
     * Matrix represents an unassigned card as 0; keep that blank in the UI.
     */
    protected function cardValueFromResponse(?\SimpleXMLElement $value): string
    {
        $card = trim((string) $value);

        return $card === '0' ? '' : $card;
    }

    /**
     * Build base URL for Matrix HTTP device.
     */
    protected function buildBaseUrl(Device $device): string
    {
        if ($device->connection_mode === 'remote') {
            return app(\App\Services\Bridge\BridgeGateway::class)->endpoint($device);
        }
        $protocol = $device->protocol ?: 'http';
        $ip = $device->ip_address;
        $port = $device->port ?: 80;

        return "{$protocol}://{$ip}:{$port}";
    }
}
