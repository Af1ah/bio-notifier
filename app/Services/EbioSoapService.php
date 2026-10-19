<?php

namespace App\Services;

use App\Models\Device;
use App\Services\Leave\LeaveSettingsService;
use App\Models\Organisation;
use App\Models\User;
use App\Services\Devices\DeviceIntegration;
use App\Services\Tenancy\TenantContext;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class EbioSoapService
{
    /**
     * Sync users from eBioServer to the tenant database.
     */
    public function syncUsers(Organisation $organisation): array
    {
        return TenantContext::run($organisation, function () use ($organisation): array {
            DeviceIntegration::requireEbio($organisation);

            if (empty($organisation->ebio_url) || empty($organisation->ebio_soap_username)) {
                throw new \Exception('eBioServer SOAP credentials are not configured for this organisation.');
            }

            $url = rtrim($organisation->ebio_url, '/').'/webservice.asmx';

            // 1. Fetch all employee codes
            $codesXml = '<?xml version="1.0" encoding="utf-8"?>
        <soap:Envelope xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance" xmlns:xsd="http://www.w3.org/2001/XMLSchema" xmlns:soap="http://schemas.xmlsoap.org/soap/envelope/">
          <soap:Body>
            <GetEmployeeCodes xmlns="http://tempuri.org/">
              <UserName>'.$this->xml($organisation->ebio_soap_username).'</UserName>
              <Password>'.$this->xml($organisation->ebio_soap_password).'</Password>
              <EmployeeLocation></EmployeeLocation>
            </GetEmployeeCodes>
          </soap:Body>
        </soap:Envelope>';

            $codesResponse = $this->http()->withHeaders([
                'Content-Type' => 'text/xml; charset=utf-8',
                'SOAPAction' => '"http://tempuri.org/GetEmployeeCodes"',
            ])->send('POST', $url, [
                'body' => $codesXml,
            ]);

            if (! $codesResponse->successful()) {
                throw new \Exception('Failed to fetch employee codes from eBioServer. HTTP Status: '.$codesResponse->status());
            }

            // Parse codes
            $matches = [1 => $this->result($codesResponse->body(), 'GetEmployeeCodesResult')];
            $result = $matches[1] ?? '';

            if (strtolower(trim($result)) === 'error') {
                throw new \RuntimeException('eBioServer rejected the synchronization request.');
            }
            if (trim($result) === '') {
                return ['synced' => 0, 'errors' => 0];
            }

            $codes = array_filter(explode(',', $result));
            $synced = 0;
            $errors = 0;

            foreach ($codes as $code) {
                $code = trim($code);
                if (empty($code)) {
                    continue;
                }

                // 2. Fetch details for each employee
                $detailsXml = '<?xml version="1.0" encoding="utf-8"?>
            <soap:Envelope xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance" xmlns:xsd="http://www.w3.org/2001/XMLSchema" xmlns:soap="http://schemas.xmlsoap.org/soap/envelope/">
              <soap:Body>
                <GetEmployeeDetails xmlns="http://tempuri.org/">
                  <UserName>'.$this->xml($organisation->ebio_soap_username).'</UserName>
                  <Password>'.$this->xml($organisation->ebio_soap_password).'</Password>
                  <EmployeeCode>'.$this->xml($code).'</EmployeeCode>
                </GetEmployeeDetails>
              </soap:Body>
            </soap:Envelope>';

                $detailsResponse = $this->http()->withHeaders([
                    'Content-Type' => 'text/xml; charset=utf-8',
                    'SOAPAction' => '"http://tempuri.org/GetEmployeeDetails"',
                ])->send('POST', $url, [
                    'body' => $detailsXml,
                ]);

                if (! $detailsResponse->successful()) {
                    throw new \RuntimeException('eBioServer employee detail request failed.');
                }
                $detMatches = [1 => $this->result($detailsResponse->body(), 'GetEmployeeDetailsResult')];
                $detailsString = $detMatches[1] ?? '';

                if ($detailsString === 'error' || empty($detailsString)) {
                    $errors++;

                    continue;
                }

                // Parse key=value,key=value
                $parts = explode(',', $detailsString);
                $employeeData = [];
                foreach ($parts as $part) {
                    if (str_contains($part, '=')) {
                        [$k, $v] = explode('=', $part, 2);
                        $employeeData[trim($k)] = trim($v);
                    }
                }

                if (isset($employeeData['EmployeeName'])) {
                    $this->updateUserFromEmployeeDetails($code, $employeeData);
                    $synced++;
                }
            }

            return ['synced' => $synced, 'errors' => $errors];
        });
    }

    /**
     * Update only fields owned by the eBio device integration.
     *
     * Application access (privilege), passwords, and the local enabled state
     * deliberately remain under Secumax control.
     */
    public function updateUserFromEmployeeDetails(string $code, array $employeeData): User
    {
        $user = User::firstOrNew(['pin' => $code]);

        if (! $user->exists) {
            $user->privilege = 0;
            $user->is_enabled = true;
        }

        $user->name = $employeeData['EmployeeName'] ?? $user->name ?? "User {$code}";
        $user->device_privilege = $this->devicePrivilege($employeeData['EmployeeRole'] ?? null);

        if (array_key_exists('EmployeeVerificationType', $employeeData)) {
            $verificationType = trim((string) $employeeData['EmployeeVerificationType']);
            $user->device_verification_type = $verificationType !== '' ? $verificationType : null;
        }

        $user->save();

        return $user;
    }

    private function devicePrivilege(mixed $role): int
    {
        return str_contains(strtolower((string) $role), 'admin') ? 14 : 0;
    }

    /**
     * Sync devices from eBioServer to the tenant database.
     */
    public function syncDevices(Organisation $organisation): array
    {
        return TenantContext::run($organisation, function () use ($organisation): array {
            DeviceIntegration::requireEbio($organisation);

            if (empty($organisation->ebio_url) || empty($organisation->ebio_soap_username)) {
                throw new \Exception('eBioServer SOAP credentials are not configured for this organisation.');
            }

            $url = rtrim($organisation->ebio_url, '/').'/webservice.asmx';

            $xml = '<?xml version="1.0" encoding="utf-8"?>
        <soap:Envelope xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance" xmlns:xsd="http://www.w3.org/2001/XMLSchema" xmlns:soap="http://schemas.xmlsoap.org/soap/envelope/">
          <soap:Body>
            <GetDeviceList xmlns="http://tempuri.org/">
              <UserName>'.$this->xml($organisation->ebio_soap_username).'</UserName>
              <Password>'.$this->xml($organisation->ebio_soap_password).'</Password>
              <Location></Location>
            </GetDeviceList>
          </soap:Body>
        </soap:Envelope>';

            $response = $this->http()->withHeaders([
                'Content-Type' => 'text/xml; charset=utf-8',
                'SOAPAction' => '"http://tempuri.org/GetDeviceList"',
            ])->send('POST', $url, [
                'body' => $xml,
            ]);

            if (! $response->successful()) {
                throw new \Exception('Failed to fetch devices from eBioServer. HTTP Status: '.$response->status());
            }

            $matches = [1 => $this->result($response->body(), 'GetDeviceListResult')];
            $result = $matches[1] ?? '';

            if (strtolower(trim($result)) === 'error') {
                throw new \RuntimeException('eBioServer rejected the synchronization request.');
            }
            if (trim($result) === '') {
                return ['synced' => 0];
            }

            // Format is: Name,SerialNumber,Location;Name2,SerialNumber2,Location2;
            $devicesStr = array_filter(explode(';', $result));
            $synced = 0;

            foreach ($devicesStr as $deviceStr) {
                $deviceStr = trim($deviceStr);
                if (empty($deviceStr)) {
                    continue;
                }

                $parts = explode(',', $deviceStr);
                if (count($parts) >= 2) {
                    $name = trim($parts[0]);
                    $serialNumber = trim($parts[1]);
                    $location = isset($parts[2]) ? trim($parts[2]) : null;

                    $device = Device::firstOrNew(['serial_number' => $serialNumber]);
                    $device->name = $name;
                    $device->status = 'online';

                    $options = $device->options ?? [];
                    if ($location) {
                        $options['location'] = $location;
                    }
                    $device->options = $options;
                    $device->last_sync_at = now();

                    // Fetch actual last ping time from eBioServer
                    $pingXml = '<?xml version="1.0" encoding="utf-8"?>
                <soap:Envelope xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance" xmlns:xsd="http://www.w3.org/2001/XMLSchema" xmlns:soap="http://schemas.xmlsoap.org/soap/envelope/">
                  <soap:Body>
                    <GetDeviceLastPing xmlns="http://tempuri.org/">
                      <UserName>'.$this->xml($organisation->ebio_soap_username).'</UserName>
                      <Password>'.$this->xml($organisation->ebio_soap_password).'</Password>
                      <DeviceSerialNumber>'.$this->xml($serialNumber).'</DeviceSerialNumber>
                    </GetDeviceLastPing>
                  </soap:Body>
                </soap:Envelope>';

                    $pingResponse = $this->http()->withHeaders([
                        'Content-Type' => 'text/xml; charset=utf-8',
                        'SOAPAction' => '"http://tempuri.org/GetDeviceLastPing"',
                    ])->send('POST', $url, [
                        'body' => $pingXml,
                    ]);

                    if ($pingResponse->successful()) {
                        $pingMatches = [1 => $this->result($pingResponse->body(), 'GetDeviceLastPingResult')];
                        $pingStr = $pingMatches[1] ?? '';
                        if (! empty($pingStr) && $pingStr !== 'error') {
                            try {
                                // eBio reports a wall-clock value without an offset. Interpret it
                                // using the tenant's configured timezone, then persist it as UTC.
                                $device->last_activity_at = Carbon::parse($pingStr, LeaveSettingsService::timezone())->utc();
                            } catch (\Exception $e) {
                                $device->last_activity_at = now();
                            }
                        } else {
                            $device->last_activity_at = now();
                        }
                    } else {
                        $device->last_activity_at = now();
                    }

                    $device->save();

                    $synced++;
                }
            }

            return ['synced' => $synced];
        });
    }

    /**
     * Push or update user data on eBioServer (which pushes to devices based on location).
     */
    public function pushUser(Organisation $organisation, User $user, string $location = ''): bool
    {
        DeviceIntegration::requireEbio($organisation);

        if (empty($organisation->ebio_url) || empty($organisation->ebio_soap_username)) {
            throw new \Exception('eBioServer SOAP credentials are not configured for this organisation.');
        }

        $url = rtrim($organisation->ebio_url, '/').'/webservice.asmx';

        $role = (int) $user->device_privilege === 14 ? 'Admin Users' : 'Normal Users';
        $cardNumber = $user->card_number ?? '';

        // eBio expects a JPEG here, never the encoded face-recognition template.
        $photo = filled($user->ai_face_photo_path)
            ? app(\App\Services\Devices\EmployeeBiometrics::class)->encodedPhoto($user)
            : '';

        $expiryFrom = $user->valid_from ? Carbon::parse($user->valid_from)->format('Y-m-d') : '';
        $expiryTo = $user->valid_to ? Carbon::parse($user->valid_to)->format('Y-m-d') : '';

        // Disable on device by expiring them yesterday
        if (! $user->is_enabled) {
            $expiryTo = Carbon::yesterday()->format('Y-m-d');
        }

        $verificationType = ! empty($photo) ? '15' : ''; // 15 = Face

        $xml = '<?xml version="1.0" encoding="utf-8"?>
        <soap:Envelope xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance" xmlns:xsd="http://www.w3.org/2001/XMLSchema" xmlns:soap="http://schemas.xmlsoap.org/soap/envelope/">
          <soap:Body>
            <UpdateEmployeeEx xmlns="http://tempuri.org/">
              <UserName>'.$this->xml($organisation->ebio_soap_username).'</UserName>
              <Password>'.$this->xml($organisation->ebio_soap_password).'</Password>
              <EmployeeCode>'.$this->xml($user->pin).'</EmployeeCode>
              <EmployeeName>'.$this->xml($user->name).'</EmployeeName>
              <EmployeeLocation>'.$this->xml($location).'</EmployeeLocation>
              <EmployeeRole>'.$this->xml($role).'</EmployeeRole>
              <EmployeeVerificationType>'.$this->xml($verificationType).'</EmployeeVerificationType>
              <EmployeeExpiryFrom>'.$this->xml($expiryFrom).'</EmployeeExpiryFrom>
              <EmployeeExpiryTo>'.$this->xml($expiryTo).'</EmployeeExpiryTo>
              <EmployeeCardNumber>'.$this->xml($cardNumber).'</EmployeeCardNumber>
              <GroupId>'.$this->xml(($user->group ?? 1)).'</GroupId>
              <EmployeePhoto>'.$this->xml($photo).'</EmployeePhoto>
            </UpdateEmployeeEx>
          </soap:Body>
        </soap:Envelope>';

        $response = $this->http()->withHeaders([
            'Content-Type' => 'text/xml; charset=utf-8',
            'SOAPAction' => '"http://tempuri.org/UpdateEmployeeEx"',
        ])->send('POST', $url, [
            'body' => $xml,
        ]);

        if (! $response->successful()) {
            Log::error("eBioServer Webhook: Failed to push user {$user->pin} to eBioServer. HTTP Status: ".$response->status());

            return false;
        }

        $matches = [1 => $this->result($response->body(), 'UpdateEmployeeExResult')];
        $result = $matches[1] ?? '';

        if ($result === 'success' || strtolower($result) === 'success') {
            return true;
        }

        Log::error("eBioServer Webhook: API returned error for UpdateEmployeeEx for user {$user->pin}: {$result}");

        return false;
    }

    /** eBio decrypts EmployeesBio/AI itself; its SOAP result is the Base64 JPEG, not a recognition template. */
    public function employeePhoto(Organisation $organisation, string $employeeCode): ?string
    {
        DeviceIntegration::requireEbio($organisation);
        if (blank($organisation->ebio_url) || blank($organisation->ebio_soap_username)) {
            throw new \RuntimeException('eBioServer SOAP credentials are not configured.');
        }
        $xml = '<?xml version="1.0" encoding="utf-8"?>'
            .'<soap:Envelope xmlns:soap="http://schemas.xmlsoap.org/soap/envelope/"><soap:Body>'
            .'<GetEmployeePhoto xmlns="http://tempuri.org/"><UserName>'.$this->xml($organisation->ebio_soap_username).'</UserName>'
            .'<Password>'.$this->xml($organisation->ebio_soap_password).'</Password><EmployeeCode>'.$this->xml($employeeCode).'</EmployeeCode>'
            .'</GetEmployeePhoto></soap:Body></soap:Envelope>';
        $response = $this->http()->withoutRedirecting()->withHeaders([
            'Content-Type' => 'text/xml; charset=utf-8', 'SOAPAction' => '"http://tempuri.org/GetEmployeePhoto"',
        ])->send('POST', rtrim($organisation->ebio_url, '/').'/webservice.asmx', ['body' => $xml]);
        if (! $response->successful() || strlen($response->body()) > 1500000) {
            throw new \RuntimeException('The employee photo could not be read from eBioServer.');
        }
        $content = $this->result($response->body(), 'GetEmployeePhotoResult');
        if ($content === '') {
            return null;
        }
        if (strtolower($content) === 'error') {
            throw new \RuntimeException('eBioServer could not return this employee photo. Check the API access and employee record.');
        }
        foreach (['data:image/jpeg;base64,', 'data:image/jpg;base64,'] as $prefix) {
            if (str_starts_with($content, $prefix)) {
                $content = substr($content, strlen($prefix));
                break;
            }
        }
        $content = preg_replace('/[\r\n ]/', '', $content);
        $bytes = strlen($content) <= 1048576 ? base64_decode($content, true) : false;
        $image = $bytes !== false ? @getimagesizefromstring($bytes) : false;
        if (! $bytes || strlen($bytes) > 786432 || ! is_array($image) || ($image['mime'] ?? null) !== 'image/jpeg') {
            throw new \RuntimeException('eBioServer returned no valid JPEG photo. Face templates cannot be displayed as photos.');
        }
        return $bytes;
    }

    /**
     * Delete user from eBioServer.
     */
    public function deleteUser(Organisation $organisation, string $employeeCode, string $location = ''): bool
    {
        DeviceIntegration::requireEbio($organisation);

        if (empty($organisation->ebio_url) || empty($organisation->ebio_soap_username)) {
            throw new \Exception('eBioServer SOAP credentials are not configured for this organisation.');
        }

        $url = rtrim($organisation->ebio_url, '/').'/webservice.asmx';

        $xml = '<?xml version="1.0" encoding="utf-8"?>
        <soap:Envelope xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance" xmlns:xsd="http://www.w3.org/2001/XMLSchema" xmlns:soap="http://schemas.xmlsoap.org/soap/envelope/">
          <soap:Body>
            <DeleteEmployee xmlns="http://tempuri.org/">
              <UserName>'.$this->xml($organisation->ebio_soap_username).'</UserName>
              <Password>'.$this->xml($organisation->ebio_soap_password).'</Password>
              <EmployeeCode>'.$this->xml($employeeCode).'</EmployeeCode>';

        if (! empty($location)) {
            $xml .= '<Location>'.$this->xml($location).'</Location>';
        }

        $xml .= '
            </DeleteEmployee>
          </soap:Body>
        </soap:Envelope>';

        $response = $this->http()->withHeaders([
            'Content-Type' => 'text/xml; charset=utf-8',
            'SOAPAction' => '"http://tempuri.org/DeleteEmployee"',
        ])->send('POST', $url, [
            'body' => $xml,
        ]);

        if (! $response->successful()) {
            return false;
        }

        $matches = [1 => $this->result($response->body(), 'DeleteEmployeeResult')];
        $result = $matches[1] ?? '';

        $resultLower = strtolower($result);

        // Treat "success" and "not found" variants as successful deletion
        if ($resultLower === 'success' || str_contains($resultLower, 'not found') || str_contains($resultLower, 'does not exist')) {
            return true;
        }

        return false;
    }

    /**
     * Reboot a device remotely.
     */
    public function rebootDevice(Organisation $organisation, string $serialNumber): bool
    {
        DeviceIntegration::requireEbio($organisation);

        if (empty($organisation->ebio_url) || empty($organisation->ebio_soap_username)) {
            throw new \Exception('eBioServer SOAP credentials are not configured for this organisation.');
        }

        $url = rtrim($organisation->ebio_url, '/').'/webservice.asmx';

        $xml = '<?xml version="1.0" encoding="utf-8"?>
        <soap:Envelope xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance" xmlns:xsd="http://www.w3.org/2001/XMLSchema" xmlns:soap="http://schemas.xmlsoap.org/soap/envelope/">
          <soap:Body>
            <DeviceCommand_Reboot xmlns="http://tempuri.org/">
              <UserName>'.$this->xml($organisation->ebio_soap_username).'</UserName>
              <Password>'.$this->xml($organisation->ebio_soap_password).'</Password>
              <DeviceSerialNumber>'.$this->xml($serialNumber).'</DeviceSerialNumber>
            </DeviceCommand_Reboot>
          </soap:Body>
        </soap:Envelope>';

        $response = $this->http()->withHeaders([
            'Content-Type' => 'text/xml; charset=utf-8',
            'SOAPAction' => '"http://tempuri.org/DeviceCommand_Reboot"',
        ])->send('POST', $url, [
            'body' => $xml,
        ]);

        if (! $response->successful()) {
            return false;
        }

        $body = $response->body();

        $matches = [1 => $this->result($body, 'DeviceCommand_RebootResult')];
        $result = $matches[1] ?? '';

        return strtolower($result) === 'success' || $result === '';
    }

    /**
     * Clear all logs on a device remotely.
     */
    public function clearDeviceLogs(Organisation $organisation, string $serialNumber): bool
    {
        DeviceIntegration::requireEbio($organisation);

        if (empty($organisation->ebio_url) || empty($organisation->ebio_soap_username)) {
            throw new \Exception('eBioServer SOAP credentials are not configured for this organisation.');
        }

        $url = rtrim($organisation->ebio_url, '/').'/webservice.asmx';

        $xml = '<?xml version="1.0" encoding="utf-8"?>
        <soap:Envelope xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance" xmlns:xsd="http://www.w3.org/2001/XMLSchema" xmlns:soap="http://schemas.xmlsoap.org/soap/envelope/">
          <soap:Body>
            <DeviceCommand_ClearLogs xmlns="http://tempuri.org/">
              <UserName>'.$this->xml($organisation->ebio_soap_username).'</UserName>
              <Password>'.$this->xml($organisation->ebio_soap_password).'</Password>
              <DeviceSerialNumber>'.$this->xml($serialNumber).'</DeviceSerialNumber>
            </DeviceCommand_ClearLogs>
          </soap:Body>
        </soap:Envelope>';

        $response = $this->http()->withHeaders([
            'Content-Type' => 'text/xml; charset=utf-8',
            'SOAPAction' => '"http://tempuri.org/DeviceCommand_ClearLogs"',
        ])->send('POST', $url, [
            'body' => $xml,
        ]);

        if (! $response->successful()) {
            return false;
        }

        $body = $response->body();

        $matches = [1 => $this->result($body, 'DeviceCommand_ClearLogsResult')];
        $result = $matches[1] ?? '';

        return strtolower($result) === 'success' || $result === '';
    }

    /**
     * Reset transaction stamp on the device (force fetch logs)
     */
    public function resetTransactionStamp(Organisation $organisation, string $serialNumber): bool
    {
        DeviceIntegration::requireEbio($organisation);

        if (empty($organisation->ebio_url) || empty($organisation->ebio_soap_username)) {
            throw new \Exception('eBioServer SOAP credentials are not configured for this organisation.');
        }

        $url = rtrim($organisation->ebio_url, '/').'/webservice.asmx';

        $xml = '<?xml version="1.0" encoding="utf-8"?>
        <soap:Envelope xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance" xmlns:xsd="http://www.w3.org/2001/XMLSchema" xmlns:soap="http://schemas.xmlsoap.org/soap/envelope/">
          <soap:Body>
            <DeviceCommand_ResetTransactionStamp xmlns="http://tempuri.org/">
              <UserName>'.$this->xml($organisation->ebio_soap_username).'</UserName>
              <Password>'.$this->xml($organisation->ebio_soap_password).'</Password>
              <DeviceSerialNumber>'.$this->xml($serialNumber).'</DeviceSerialNumber>
            </DeviceCommand_ResetTransactionStamp>
          </soap:Body>
        </soap:Envelope>';

        $response = $this->http()->withHeaders([
            'Content-Type' => 'text/xml; charset=utf-8',
            'SOAPAction' => '"http://tempuri.org/DeviceCommand_ResetTransactionStamp"',
        ])->send('POST', $url, [
            'body' => $xml,
        ]);

        if (! $response->successful()) {
            return false;
        }

        $body = $response->body();

        $matches = [1 => $this->result($body, 'DeviceCommand_ResetTransactionStampResult')];
        $result = $matches[1] ?? '';

        return strtolower($result) === 'success' || $result === '';
    }

    /**
     * Reset OP stamp on the device
     */
    public function resetOPStamp(Organisation $organisation, string $serialNumber): bool
    {
        DeviceIntegration::requireEbio($organisation);

        if (empty($organisation->ebio_url) || empty($organisation->ebio_soap_username)) {
            throw new \Exception('eBioServer SOAP credentials are not configured for this organisation.');
        }

        $url = rtrim($organisation->ebio_url, '/').'/webservice.asmx';

        $xml = '<?xml version="1.0" encoding="utf-8"?>
        <soap:Envelope xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance" xmlns:xsd="http://www.w3.org/2001/XMLSchema" xmlns:soap="http://schemas.xmlsoap.org/soap/envelope/">
          <soap:Body>
            <DeviceCommand_ResetOPStamp xmlns="http://tempuri.org/">
              <UserName>'.$this->xml($organisation->ebio_soap_username).'</UserName>
              <Password>'.$this->xml($organisation->ebio_soap_password).'</Password>
              <DeviceSerialNumber>'.$this->xml($serialNumber).'</DeviceSerialNumber>
            </DeviceCommand_ResetOPStamp>
          </soap:Body>
        </soap:Envelope>';

        $response = $this->http()->withHeaders([
            'Content-Type' => 'text/xml; charset=utf-8',
            'SOAPAction' => '"http://tempuri.org/DeviceCommand_ResetOPStamp"',
        ])->send('POST', $url, [
            'body' => $xml,
        ]);

        if (! $response->successful()) {
            return false;
        }

        $body = $response->body();

        $matches = [1 => $this->result($body, 'DeviceCommand_ResetOPStampResult')];
        $result = $matches[1] ?? '';

        return strtolower($result) === 'success' || $result === '';
    }

    /**
     * Block or unblock a user from a device.
     */
    public function blockUserFromDoor(Organisation $organisation, string $serialNumber, string $employeeCode, bool $blockUser): bool
    {
        DeviceIntegration::requireEbio($organisation);

        if (empty($organisation->ebio_url) || empty($organisation->ebio_soap_username)) {
            throw new \Exception('eBioServer SOAP credentials are not configured for this organisation.');
        }

        $url = rtrim($organisation->ebio_url, '/').'/webservice.asmx';
        $blockStr = $blockUser ? 'true' : 'false';

        $xml = '<?xml version="1.0" encoding="utf-8"?>
        <soap:Envelope xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance" xmlns:xsd="http://www.w3.org/2001/XMLSchema" xmlns:soap="http://schemas.xmlsoap.org/soap/envelope/">
          <soap:Body>
            <DeviceCommand_BlockUnBlockUser xmlns="http://tempuri.org/">
              <UserName>'.$this->xml($organisation->ebio_soap_username).'</UserName>
              <Password>'.$this->xml($organisation->ebio_soap_password).'</Password>
              <DeviceSerialNumber>'.$this->xml($serialNumber).'</DeviceSerialNumber>
              <EmployeeCode>'.$this->xml($employeeCode).'</EmployeeCode>
              <BlockUser>'.$this->xml($blockStr).'</BlockUser>
            </DeviceCommand_BlockUnBlockUser>
          </soap:Body>
        </soap:Envelope>';

        $response = $this->http()->withHeaders([
            'Content-Type' => 'text/xml; charset=utf-8',
            'SOAPAction' => '"http://tempuri.org/DeviceCommand_BlockUnBlockUser"',
        ])->send('POST', $url, [
            'body' => $xml,
        ]);

        if (! $response->successful()) {
            return false;
        }

        $body = $response->body();

        $matches = [1 => $this->result($body, 'DeviceCommand_BlockUnBlockUserResult')];
        $result = $matches[1] ?? '';

        $isSuccess = strtolower($result) === 'success' || ($result === '');
        if (! $isSuccess) {
            Log::error('Block/Unblock user API failed. Response: '.$body);
        }

        return $isSuccess;
    }

    /**
     * Trigger Fingerprint Enrollment on the device
     */
    public function enrollFingerprint(Organisation $organisation, string $serialNumber, string $employeeCode, int $fingerIndex): bool
    {
        DeviceIntegration::requireEbio($organisation);

        $payload = '<?xml version="1.0" encoding="utf-8"?>
        <soap:Envelope xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance" xmlns:xsd="http://www.w3.org/2001/XMLSchema" xmlns:soap="http://schemas.xmlsoap.org/soap/envelope/">
          <soap:Body>
            <DeviceCommand_EnrollFP xmlns="http://tempuri.org/">
              <UserName>'.$this->xml($organisation->ebio_soap_username).'</UserName>
              <Password>'.$this->xml($organisation->ebio_soap_password).'</Password>
              <DeviceSerialNumber>'.$this->xml($serialNumber).'</DeviceSerialNumber>
              <EmployeeCode>'.$this->xml($employeeCode).'</EmployeeCode>
              <FPIndex>'.$this->xml($fingerIndex).'</FPIndex>
            </DeviceCommand_EnrollFP>
          </soap:Body>
        </soap:Envelope>';

        $response = $this->http()->withHeaders([
            'Content-Type' => 'text/xml; charset=utf-8',
            'SOAPAction' => '"http://tempuri.org/DeviceCommand_EnrollFP"',
        ])->send('POST', rtrim($organisation->ebio_url, '/').'/webservice.asmx', [
            'body' => $payload,
        ]);

        if (! $response->successful()) {
            return false;
        }

        $body = $response->body();

        $matches = [1 => $this->result($body, 'DeviceCommand_EnrollFPResult')];
        $result = $matches[1] ?? '';

        $isSuccess = strtolower($result) === 'success' || ($result === '');
        if (! $isSuccess) {
            Log::error('EnrollFP API failed. Response: '.$body);
        }

        return $isSuccess;
    }

    /**
     * Trigger Face Enrollment on the device (with fallback to EnrollFaceEx)
     */
    public function enrollFace(Organisation $organisation, string $serialNumber, string $employeeCode): bool
    {
        DeviceIntegration::requireEbio($organisation);

        $payload = '<?xml version="1.0" encoding="utf-8"?>
        <soap:Envelope xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance" xmlns:xsd="http://www.w3.org/2001/XMLSchema" xmlns:soap="http://schemas.xmlsoap.org/soap/envelope/">
          <soap:Body>
            <DeviceCommand_EnrollFace xmlns="http://tempuri.org/">
              <UserName>'.$this->xml($organisation->ebio_soap_username).'</UserName>
              <Password>'.$this->xml($organisation->ebio_soap_password).'</Password>
              <DeviceSerialNumber>'.$this->xml($serialNumber).'</DeviceSerialNumber>
              <EmployeeCode>'.$this->xml($employeeCode).'</EmployeeCode>
            </DeviceCommand_EnrollFace>
          </soap:Body>
        </soap:Envelope>';

        $response = $this->http()->withHeaders([
            'Content-Type' => 'text/xml; charset=utf-8',
            'SOAPAction' => '"http://tempuri.org/DeviceCommand_EnrollFace"',
        ])->send('POST', rtrim($organisation->ebio_url, '/').'/webservice.asmx', [
            'body' => $payload,
        ]);

        $body = $response->body();

        $isSuccess = false;
        if ($response->successful()) {
            try {
                $result = $this->result($body, 'DeviceCommand_EnrollFaceResult');
                $isSuccess = strtolower($result) === 'success' || $result === '';
            } catch (\RuntimeException $exception) {
                // Older eBio servers may only support EnrollFaceEx.
                $isSuccess = false;
            }
        }

        if ($isSuccess) {
            return true;
        }

        Log::info('EnrollFace failed, attempting EnrollFaceEx for Employee: '.$employeeCode);

        // Fallback to EnrollFaceEx
        $payloadEx = '<?xml version="1.0" encoding="utf-8"?>
        <soap:Envelope xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance" xmlns:xsd="http://www.w3.org/2001/XMLSchema" xmlns:soap="http://schemas.xmlsoap.org/soap/envelope/">
          <soap:Body>
            <DeviceCommand_EnrollFaceEx xmlns="http://tempuri.org/">
              <UserName>'.$this->xml($organisation->ebio_soap_username).'</UserName>
              <Password>'.$this->xml($organisation->ebio_soap_password).'</Password>
              <DeviceSerialNumber>'.$this->xml($serialNumber).'</DeviceSerialNumber>
              <EmployeeCode>'.$this->xml($employeeCode).'</EmployeeCode>
            </DeviceCommand_EnrollFaceEx>
          </soap:Body>
        </soap:Envelope>';

        $responseEx = $this->http()->withHeaders([
            'Content-Type' => 'text/xml; charset=utf-8',
            'SOAPAction' => '"http://tempuri.org/DeviceCommand_EnrollFaceEx"',
        ])->send('POST', rtrim($organisation->ebio_url, '/').'/webservice.asmx', [
            'body' => $payloadEx,
        ]);

        if (! $responseEx->successful()) {
            return false;
        }
        $bodyEx = $responseEx->body();

        $matches = [1 => $this->result($bodyEx, 'DeviceCommand_EnrollFaceExResult')];
        $resultEx = $matches[1] ?? '';

        $isSuccessEx = strtolower($resultEx) === 'success' || $resultEx === '';
        if (! $isSuccessEx) {
            Log::error('EnrollFaceEx API failed. Response: '.$bodyEx);
        }

        return $isSuccessEx;
    }

    /**
     * Helper to fetch users from external service.
     */
    public function unlockDoor(Organisation $organisation, string $serialNumber): bool
    {
        DeviceIntegration::requireEbio($organisation);

        if (empty($organisation->ebio_url) || empty($organisation->ebio_soap_username)) {
            throw new \Exception('eBioServer SOAP credentials are not configured for this organisation.');
        }

        $url = rtrim($organisation->ebio_url, '/').'/webservice.asmx';

        $xml = '<?xml version="1.0" encoding="utf-8"?>
        <soap:Envelope xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance" xmlns:xsd="http://www.w3.org/2001/XMLSchema" xmlns:soap="http://schemas.xmlsoap.org/soap/envelope/">
          <soap:Body>
            <DeviceCommand_UnlockDoor xmlns="http://tempuri.org/">
              <UserName>'.$this->xml($organisation->ebio_soap_username).'</UserName>
              <Password>'.$this->xml($organisation->ebio_soap_password).'</Password>
              <DeviceSerialNumber>'.$this->xml($serialNumber).'</DeviceSerialNumber>
            </DeviceCommand_UnlockDoor>
          </soap:Body>
        </soap:Envelope>';

        $response = $this->http()->withHeaders([
            'Content-Type' => 'text/xml; charset=utf-8',
            'SOAPAction' => '"http://tempuri.org/DeviceCommand_UnlockDoor"',
        ])->send('POST', $url, [
            'body' => $xml,
        ]);

        if (! $response->successful()) {
            return false;
        }

        $body = $response->body();

        $matches = [1 => $this->result($body, 'DeviceCommand_UnlockDoorResult')];
        $result = $matches[1] ?? '';

        return strtolower($result) === 'success' || $result === '';
    }

    /**
     * Add or update device on eBioServer.
     */
    public function addDevice(Organisation $organisation, array $data): bool
    {
        DeviceIntegration::requireEbio($organisation);

        if (empty($organisation->ebio_url) || empty($organisation->ebio_soap_username)) {
            throw new \Exception('eBioServer SOAP credentials are not configured for this organisation.');
        }

        $url = rtrim($organisation->ebio_url, '/').'/webservice.asmx';

        $xml = '<?xml version="1.0" encoding="utf-8"?>
        <soap:Envelope xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance" xmlns:xsd="http://www.w3.org/2001/XMLSchema" xmlns:soap="http://schemas.xmlsoap.org/soap/envelope/">
          <soap:Body>
            <UpdateDevice xmlns="http://tempuri.org/">
              <UserName>'.$this->xml($organisation->ebio_soap_username).'</UserName>
              <Password>'.$this->xml($organisation->ebio_soap_password).'</Password>
              <DeviceSerialNumber>'.$this->xml($data['serial_number']).'</DeviceSerialNumber>
              <DeviceName>'.$this->xml($data['name']).'</DeviceName>
              <DeviceDiretion>'.$this->xml(($data['direction'] ?? '')).'</DeviceDiretion>
              <DeviceType>'.$this->xml(($data['device_type'] ?? '')).'</DeviceType>
              <TimeZone>'.$this->xml(($data['time_zone'] ?? '')).'</TimeZone>
              <DeviceActivationCode>'.$this->xml(($data['activation_code'] ?? '')).'</DeviceActivationCode>
              <Location>'.$this->xml($data['location'] ?? '').'</Location>
              <IsAttendanceDevice>'.$this->xml(($data['is_attendance_device'] ?? 'true')).'</IsAttendanceDevice>
            </UpdateDevice>
          </soap:Body>
        </soap:Envelope>';

        $response = $this->http()->withHeaders([
            'Content-Type' => 'text/xml; charset=utf-8',
            'SOAPAction' => '"http://tempuri.org/UpdateDevice"',
        ])->send('POST', $url, [
            'body' => $xml,
        ]);

        if (! $response->successful()) {
            return false;
        }

        $matches = [1 => $this->result($response->body(), 'UpdateDeviceResult')];
        $result = $matches[1] ?? '';

        return strtolower($result) === 'success';
    }

    private function xml(mixed $value): string
    {
        return htmlspecialchars((string) $value, ENT_XML1 | ENT_QUOTES, 'UTF-8');
    }

    private function http(): PendingRequest
    {
        return Http::connectTimeout(5)->timeout(20);
    }

    private function result(string $body, string $name): string
    {
        $previous = libxml_use_internal_errors(true);
        try {
            $document = simplexml_load_string($body, \SimpleXMLElement::class, LIBXML_NONET);
            if ($document === false || $document->xpath('//*[local-name()="Fault"]')) {
                throw new \RuntimeException('Invalid or faulted eBioServer SOAP response.');
            }
            $nodes = $document->xpath('//*[local-name()="'.$name.'"]');
            if (count($nodes) !== 1) {
                throw new \RuntimeException('Missing eBioServer SOAP result: '.$name.'.');
            }

            return trim((string) $nodes[0]);
        } finally {
            libxml_clear_errors();
            libxml_use_internal_errors($previous);
        }
    }
}
