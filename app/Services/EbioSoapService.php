<?php

namespace App\Services;

use App\Models\Organisation;
use App\Models\User;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class EbioSoapService
{
    /**
     * Sync users from eBioServer to the tenant database.
     */
    public function syncUsers(Organisation $organisation): array
    {
        if (empty($organisation->ebio_url) || empty($organisation->ebio_soap_username)) {
            throw new \Exception("eBioServer SOAP credentials are not configured for this organisation.");
        }

        tenancy()->initialize($organisation);

        $url = rtrim($organisation->ebio_url, '/') . '/webservice.asmx';
        
        // 1. Fetch all employee codes
        $codesXml = '<?xml version="1.0" encoding="utf-8"?>
        <soap:Envelope xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance" xmlns:xsd="http://www.w3.org/2001/XMLSchema" xmlns:soap="http://schemas.xmlsoap.org/soap/envelope/">
          <soap:Body>
            <GetEmployeeCodes xmlns="http://tempuri.org/">
              <UserName>'.$organisation->ebio_soap_username.'</UserName>
              <Password>'.$organisation->ebio_soap_password.'</Password>
              <EmployeeLocation></EmployeeLocation>
            </GetEmployeeCodes>
          </soap:Body>
        </soap:Envelope>';

        $codesResponse = Http::withHeaders([
            'Content-Type' => 'text/xml; charset=utf-8',
            'SOAPAction' => '"http://tempuri.org/GetEmployeeCodes"'
        ])->send('POST', $url, [
            'body' => $codesXml
        ]);

        if (!$codesResponse->successful()) {
            throw new \Exception("Failed to fetch employee codes from eBioServer. HTTP Status: " . $codesResponse->status());
        }

        // Parse codes
        preg_match('/<GetEmployeeCodesResult>(.*?)<\/GetEmployeeCodesResult>/', $codesResponse->body(), $matches);
        $result = $matches[1] ?? '';
        
        if ($result === 'error' || empty($result)) {
            return ['synced' => 0, 'errors' => 0];
        }

        $codes = array_filter(explode(',', $result));
        $synced = 0;
        $errors = 0;

        foreach ($codes as $code) {
            $code = trim($code);
            if (empty($code)) continue;

            // 2. Fetch details for each employee
            $detailsXml = '<?xml version="1.0" encoding="utf-8"?>
            <soap:Envelope xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance" xmlns:xsd="http://www.w3.org/2001/XMLSchema" xmlns:soap="http://schemas.xmlsoap.org/soap/envelope/">
              <soap:Body>
                <GetEmployeeDetails xmlns="http://tempuri.org/">
                  <UserName>'.$organisation->ebio_soap_username.'</UserName>
                  <Password>'.$organisation->ebio_soap_password.'</Password>
                  <EmployeeCode>'.$code.'</EmployeeCode>
                </GetEmployeeDetails>
              </soap:Body>
            </soap:Envelope>';

            $detailsResponse = Http::withHeaders([
                'Content-Type' => 'text/xml; charset=utf-8',
                'SOAPAction' => '"http://tempuri.org/GetEmployeeDetails"'
            ])->send('POST', $url, [
                'body' => $detailsXml
            ]);

            preg_match('/<GetEmployeeDetailsResult>(.*?)<\/GetEmployeeDetailsResult>/', $detailsResponse->body(), $detMatches);
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
                // Determine privilege based on EmployeeRole
                $privilege = 0; // Normal User
                if (isset($employeeData['EmployeeRole'])) {
                    $roleStr = strtolower($employeeData['EmployeeRole']);
                    if (str_contains($roleStr, 'admin')) {
                        $privilege = 14; // Admin
                    }
                }

                User::updateOrCreate(
                    ['pin' => $code],
                    [
                        'name' => $employeeData['EmployeeName'],
                        'password' => '', // Will be set empty by default if not available
                        'privilege' => $privilege,
                        'is_enabled' => true, // Assuming enabled if returned by API
                    ]
                );
                $synced++;
            }
        }

        return ['synced' => $synced, 'errors' => $errors];
    }

    /**
     * Sync devices from eBioServer to the tenant database.
     */
    public function syncDevices(Organisation $organisation): array
    {
        if (empty($organisation->ebio_url) || empty($organisation->ebio_soap_username)) {
            throw new \Exception("eBioServer SOAP credentials are not configured for this organisation.");
        }

        tenancy()->initialize($organisation);

        $url = rtrim($organisation->ebio_url, '/') . '/webservice.asmx';
        
        $xml = '<?xml version="1.0" encoding="utf-8"?>
        <soap:Envelope xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance" xmlns:xsd="http://www.w3.org/2001/XMLSchema" xmlns:soap="http://schemas.xmlsoap.org/soap/envelope/">
          <soap:Body>
            <GetDeviceList xmlns="http://tempuri.org/">
              <UserName>'.$organisation->ebio_soap_username.'</UserName>
              <Password>'.$organisation->ebio_soap_password.'</Password>
              <Location></Location>
            </GetDeviceList>
          </soap:Body>
        </soap:Envelope>';

        $response = Http::withHeaders([
            'Content-Type' => 'text/xml; charset=utf-8',
            'SOAPAction' => '"http://tempuri.org/GetDeviceList"'
        ])->send('POST', $url, [
            'body' => $xml
        ]);

        if (!$response->successful()) {
            throw new \Exception("Failed to fetch devices from eBioServer. HTTP Status: " . $response->status());
        }

        preg_match('/<GetDeviceListResult>(.*?)<\/GetDeviceListResult>/', $response->body(), $matches);
        $result = $matches[1] ?? '';
        
        if ($result === 'error' || empty($result)) {
            return ['synced' => 0];
        }

        // Format is: Name,SerialNumber,Location;Name2,SerialNumber2,Location2;
        $devicesStr = array_filter(explode(';', $result));
        $synced = 0;

        foreach ($devicesStr as $deviceStr) {
            $deviceStr = trim($deviceStr);
            if (empty($deviceStr)) continue;
            
            $parts = explode(',', $deviceStr);
            if (count($parts) >= 2) {
                $name = trim($parts[0]);
                $serialNumber = trim($parts[1]);
                $location = isset($parts[2]) ? trim($parts[2]) : null;

                $device = \App\Models\Device::firstOrNew(['serial_number' => $serialNumber]);
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
                      <UserName>'.$organisation->ebio_soap_username.'</UserName>
                      <Password>'.$organisation->ebio_soap_password.'</Password>
                      <DeviceSerialNumber>'.$serialNumber.'</DeviceSerialNumber>
                    </GetDeviceLastPing>
                  </soap:Body>
                </soap:Envelope>';

                $pingResponse = Http::withHeaders([
                    'Content-Type' => 'text/xml; charset=utf-8',
                    'SOAPAction' => '"http://tempuri.org/GetDeviceLastPing"'
                ])->send('POST', $url, [
                    'body' => $pingXml
                ]);

                if ($pingResponse->successful()) {
                    preg_match('/<GetDeviceLastPingResult>(.*?)<\/GetDeviceLastPingResult>/', $pingResponse->body(), $pingMatches);
                    $pingStr = $pingMatches[1] ?? '';
                    if (!empty($pingStr) && $pingStr !== 'error') {
                        try {
                            $device->last_activity_at = \Illuminate\Support\Carbon::parse($pingStr);
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
    }

    /**
     * Push or update user data on eBioServer (which pushes to devices based on location).
     */
    public function pushUser(Organisation $organisation, User $user, string $location = ''): bool
    {
        if (empty($organisation->ebio_url) || empty($organisation->ebio_soap_username)) {
            throw new \Exception("eBioServer SOAP credentials are not configured for this organisation.");
        }

        $url = rtrim($organisation->ebio_url, '/') . '/webservice.asmx';
        
        $role = $user->privilege == 14 ? 'Admin Users' : 'Normal Users';
        $cardNumber = $user->card_number ?? '';
        
        // EmployeePhoto base64 can be included if available.
        $photo = '';
        if (!empty($user->face_templates)) {
            $photo = is_array($user->face_templates) ? ($user->face_templates[0] ?? '') : $user->face_templates;
        }
        
        $expiryFrom = $user->valid_from ? \Illuminate\Support\Carbon::parse($user->valid_from)->format('Y-m-d') : '';
        $expiryTo = $user->valid_to ? \Illuminate\Support\Carbon::parse($user->valid_to)->format('Y-m-d') : '';
        
        // Disable on device by expiring them yesterday
        if (!$user->is_enabled) {
            $expiryTo = \Illuminate\Support\Carbon::yesterday()->format('Y-m-d');
        }
        
        $verificationType = !empty($photo) ? '15' : ''; // 15 = Face

        $xml = '<?xml version="1.0" encoding="utf-8"?>
        <soap:Envelope xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance" xmlns:xsd="http://www.w3.org/2001/XMLSchema" xmlns:soap="http://schemas.xmlsoap.org/soap/envelope/">
          <soap:Body>
            <UpdateEmployeeEx xmlns="http://tempuri.org/">
              <UserName>'.$organisation->ebio_soap_username.'</UserName>
              <Password>'.$organisation->ebio_soap_password.'</Password>
              <EmployeeCode>'.$user->pin.'</EmployeeCode>
              <EmployeeName>'.htmlspecialchars($user->name).'</EmployeeName>
              <EmployeeLocation>'.$location.'</EmployeeLocation>
              <EmployeeRole>'.$role.'</EmployeeRole>
              <EmployeeVerificationType>'.$verificationType.'</EmployeeVerificationType>
              <EmployeeExpiryFrom>'.$expiryFrom.'</EmployeeExpiryFrom>
              <EmployeeExpiryTo>'.$expiryTo.'</EmployeeExpiryTo>
              <EmployeeCardNumber>'.$cardNumber.'</EmployeeCardNumber>
              <GroupId>'.($user->group ?? 1).'</GroupId>
              <EmployeePhoto>'.$photo.'</EmployeePhoto>
            </UpdateEmployeeEx>
          </soap:Body>
        </soap:Envelope>';

        $response = Http::withHeaders([
            'Content-Type' => 'text/xml; charset=utf-8',
            'SOAPAction' => '"http://tempuri.org/UpdateEmployeeEx"'
        ])->send('POST', $url, [
            'body' => $xml
        ]);

        if (!$response->successful()) {
            Log::error("eBioServer Webhook: Failed to push user {$user->pin} to eBioServer. HTTP Status: " . $response->status());
            return false;
        }

        preg_match('/<UpdateEmployeeExResult>(.*?)<\/UpdateEmployeeExResult>/', $response->body(), $matches);
        $result = $matches[1] ?? '';

        if ($result === 'success' || strtolower($result) === 'success') {
            return true;
        }
        
        Log::error("eBioServer Webhook: API returned error for UpdateEmployeeEx for user {$user->pin}: {$result}");
        return false;
    }

    /**
     * Delete user from eBioServer.
     */
    public function deleteUser(Organisation $organisation, string $employeeCode, string $location = ''): bool
    {
        if (empty($organisation->ebio_url) || empty($organisation->ebio_soap_username)) {
            throw new \Exception("eBioServer SOAP credentials are not configured for this organisation.");
        }

        $url = rtrim($organisation->ebio_url, '/') . '/webservice.asmx';
        
        $xml = '<?xml version="1.0" encoding="utf-8"?>
        <soap:Envelope xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance" xmlns:xsd="http://www.w3.org/2001/XMLSchema" xmlns:soap="http://schemas.xmlsoap.org/soap/envelope/">
          <soap:Body>
            <DeleteEmployee xmlns="http://tempuri.org/">
              <UserName>'.$organisation->ebio_soap_username.'</UserName>
              <Password>'.$organisation->ebio_soap_password.'</Password>
              <EmployeeCode>'.$employeeCode.'</EmployeeCode>';

        if (!empty($location)) {
            $xml .= '<Location>'.$location.'</Location>';
        }

        $xml .= '
            </DeleteEmployee>
          </soap:Body>
        </soap:Envelope>';

        $response = Http::withHeaders([
            'Content-Type' => 'text/xml; charset=utf-8',
            'SOAPAction' => '"http://tempuri.org/DeleteEmployee"'
        ])->send('POST', $url, [
            'body' => $xml
        ]);

        if (!$response->successful()) {
            return false;
        }

        preg_match('/<DeleteEmployeeResult>(.*?)<\/DeleteEmployeeResult>/', $response->body(), $matches);
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
        if (empty($organisation->ebio_url) || empty($organisation->ebio_soap_username)) {
            throw new \Exception("eBioServer SOAP credentials are not configured for this organisation.");
        }

        $url = rtrim($organisation->ebio_url, '/') . '/webservice.asmx';
        
        $xml = '<?xml version="1.0" encoding="utf-8"?>
        <soap:Envelope xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance" xmlns:xsd="http://www.w3.org/2001/XMLSchema" xmlns:soap="http://schemas.xmlsoap.org/soap/envelope/">
          <soap:Body>
            <DeviceCommand_Reboot xmlns="http://tempuri.org/">
              <UserName>'.$organisation->ebio_soap_username.'</UserName>
              <Password>'.$organisation->ebio_soap_password.'</Password>
              <DeviceSerialNumber>'.$serialNumber.'</DeviceSerialNumber>
            </DeviceCommand_Reboot>
          </soap:Body>
        </soap:Envelope>';

        $response = Http::withHeaders([
            'Content-Type' => 'text/xml; charset=utf-8',
            'SOAPAction' => '"http://tempuri.org/DeviceCommand_Reboot"'
        ])->send('POST', $url, [
            'body' => $xml
        ]);

        if (!$response->successful()) {
            return false;
        }

        $body = $response->body();

        if (str_contains($body, '<DeviceCommand_RebootResult />')) {
            return true;
        }

        preg_match('/<DeviceCommand_RebootResult>(.*?)<\/DeviceCommand_RebootResult>/', $body, $matches);
        $result = $matches[1] ?? '';

        return strtolower($result) === 'success' || empty($result) && $result !== 'error';
    }

    /**
     * Clear all logs on a device remotely.
     */
    public function clearDeviceLogs(Organisation $organisation, string $serialNumber): bool
    {
        if (empty($organisation->ebio_url) || empty($organisation->ebio_soap_username)) {
            throw new \Exception("eBioServer SOAP credentials are not configured for this organisation.");
        }

        $url = rtrim($organisation->ebio_url, '/') . '/webservice.asmx';
        
        $xml = '<?xml version="1.0" encoding="utf-8"?>
        <soap:Envelope xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance" xmlns:xsd="http://www.w3.org/2001/XMLSchema" xmlns:soap="http://schemas.xmlsoap.org/soap/envelope/">
          <soap:Body>
            <DeviceCommand_ClearLogs xmlns="http://tempuri.org/">
              <UserName>'.$organisation->ebio_soap_username.'</UserName>
              <Password>'.$organisation->ebio_soap_password.'</Password>
              <DeviceSerialNumber>'.$serialNumber.'</DeviceSerialNumber>
            </DeviceCommand_ClearLogs>
          </soap:Body>
        </soap:Envelope>';

        $response = Http::withHeaders([
            'Content-Type' => 'text/xml; charset=utf-8',
            'SOAPAction' => '"http://tempuri.org/DeviceCommand_ClearLogs"'
        ])->send('POST', $url, [
            'body' => $xml
        ]);

        if (!$response->successful()) {
            return false;
        }

        $body = $response->body();

        if (str_contains($body, '<DeviceCommand_ClearLogsResult />')) {
            return true;
        }

        preg_match('/<DeviceCommand_ClearLogsResult>(.*?)<\/DeviceCommand_ClearLogsResult>/', $body, $matches);
        $result = $matches[1] ?? '';

        return strtolower($result) === 'success' || empty($result) && $result !== 'error';
    }

    /**
     * Reset transaction stamp on the device (force fetch logs)
     */
    public function resetTransactionStamp(Organisation $organisation, string $serialNumber): bool
    {
        if (empty($organisation->ebio_url) || empty($organisation->ebio_soap_username)) {
            throw new \Exception("eBioServer SOAP credentials are not configured for this organisation.");
        }

        $url = rtrim($organisation->ebio_url, '/') . '/webservice.asmx';
        
        $xml = '<?xml version="1.0" encoding="utf-8"?>
        <soap:Envelope xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance" xmlns:xsd="http://www.w3.org/2001/XMLSchema" xmlns:soap="http://schemas.xmlsoap.org/soap/envelope/">
          <soap:Body>
            <DeviceCommand_ResetTransactionStamp xmlns="http://tempuri.org/">
              <UserName>'.$organisation->ebio_soap_username.'</UserName>
              <Password>'.$organisation->ebio_soap_password.'</Password>
              <DeviceSerialNumber>'.$serialNumber.'</DeviceSerialNumber>
            </DeviceCommand_ResetTransactionStamp>
          </soap:Body>
        </soap:Envelope>';

        $response = Http::withHeaders([
            'Content-Type' => 'text/xml; charset=utf-8',
            'SOAPAction' => '"http://tempuri.org/DeviceCommand_ResetTransactionStamp"'
        ])->send('POST', $url, [
            'body' => $xml
        ]);

        if (!$response->successful()) {
            return false;
        }

        $body = $response->body();

        if (str_contains($body, '<DeviceCommand_ResetTransactionStampResult />')) {
            return true;
        }

        preg_match('/<DeviceCommand_ResetTransactionStampResult>(.*?)<\/DeviceCommand_ResetTransactionStampResult>/', $body, $matches);
        $result = $matches[1] ?? '';

        return strtolower($result) === 'success' || empty($result) && $result !== 'error';
    }

    /**
     * Reset OP stamp on the device
     */
    public function resetOPStamp(Organisation $organisation, string $serialNumber): bool
    {
        if (empty($organisation->ebio_url) || empty($organisation->ebio_soap_username)) {
            throw new \Exception("eBioServer SOAP credentials are not configured for this organisation.");
        }

        $url = rtrim($organisation->ebio_url, '/') . '/webservice.asmx';
        
        $xml = '<?xml version="1.0" encoding="utf-8"?>
        <soap:Envelope xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance" xmlns:xsd="http://www.w3.org/2001/XMLSchema" xmlns:soap="http://schemas.xmlsoap.org/soap/envelope/">
          <soap:Body>
            <DeviceCommand_ResetOPStamp xmlns="http://tempuri.org/">
              <UserName>'.$organisation->ebio_soap_username.'</UserName>
              <Password>'.$organisation->ebio_soap_password.'</Password>
              <DeviceSerialNumber>'.$serialNumber.'</DeviceSerialNumber>
            </DeviceCommand_ResetOPStamp>
          </soap:Body>
        </soap:Envelope>';

        $response = Http::withHeaders([
            'Content-Type' => 'text/xml; charset=utf-8',
            'SOAPAction' => '"http://tempuri.org/DeviceCommand_ResetOPStamp"'
        ])->send('POST', $url, [
            'body' => $xml
        ]);

        if (!$response->successful()) {
            return false;
        }

        $body = $response->body();

        if (str_contains($body, '<DeviceCommand_ResetOPStampResult />')) {
            return true;
        }

        preg_match('/<DeviceCommand_ResetOPStampResult>(.*?)<\/DeviceCommand_ResetOPStampResult>/', $body, $matches);
        $result = $matches[1] ?? '';

        return strtolower($result) === 'success' || empty($result) && $result !== 'error';
    }

    /**
     * Block or unblock a user from a device.
     */
    public function blockUserFromDoor(Organisation $organisation, string $serialNumber, string $employeeCode, bool $blockUser): bool
    {
        if (empty($organisation->ebio_url) || empty($organisation->ebio_soap_username)) {
            throw new \Exception("eBioServer SOAP credentials are not configured for this organisation.");
        }

        $url = rtrim($organisation->ebio_url, '/') . '/webservice.asmx';
        $blockStr = $blockUser ? 'true' : 'false';
        
        $xml = '<?xml version="1.0" encoding="utf-8"?>
        <soap:Envelope xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance" xmlns:xsd="http://www.w3.org/2001/XMLSchema" xmlns:soap="http://schemas.xmlsoap.org/soap/envelope/">
          <soap:Body>
            <DeviceCommand_BlockUnBlockUser xmlns="http://tempuri.org/">
              <UserName>'.$organisation->ebio_soap_username.'</UserName>
              <Password>'.$organisation->ebio_soap_password.'</Password>
              <DeviceSerialNumber>'.$serialNumber.'</DeviceSerialNumber>
              <EmployeeCode>'.$employeeCode.'</EmployeeCode>
              <BlockUser>'.$blockStr.'</BlockUser>
            </DeviceCommand_BlockUnBlockUser>
          </soap:Body>
        </soap:Envelope>';

        $response = Http::withHeaders([
            'Content-Type' => 'text/xml; charset=utf-8',
            'SOAPAction' => '"http://tempuri.org/DeviceCommand_BlockUnBlockUser"'
        ])->send('POST', $url, [
            'body' => $xml
        ]);

        if (!$response->successful()) {
            return false;
        }

        $body = $response->body();

        if (str_contains($body, '<DeviceCommand_BlockUnBlockUserResult />')) {
            return true;
        }

        preg_match('/<DeviceCommand_BlockUnBlockUserResult>(.*?)<\/DeviceCommand_BlockUnBlockUserResult>/', $body, $matches);
        $result = $matches[1] ?? '';
        
        $isSuccess = strtolower($result) === 'success' || (empty($result) && $result !== 'error');
        if (!$isSuccess) {
            \Illuminate\Support\Facades\Log::error("Block/Unblock user API failed. Response: " . $body);
        }
        return $isSuccess;
    }

    /**
     * Trigger Fingerprint Enrollment on the device
     */
    public function enrollFingerprint(Organisation $organisation, string $serialNumber, string $employeeCode, int $fingerIndex): bool
    {
        $payload = '<?xml version="1.0" encoding="utf-8"?>
        <soap:Envelope xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance" xmlns:xsd="http://www.w3.org/2001/XMLSchema" xmlns:soap="http://schemas.xmlsoap.org/soap/envelope/">
          <soap:Body>
            <DeviceCommand_EnrollFP xmlns="http://tempuri.org/">
              <UserName>'.$organisation->ebio_soap_username.'</UserName>
              <Password>'.$organisation->ebio_soap_password.'</Password>
              <DeviceSerialNumber>'.$serialNumber.'</DeviceSerialNumber>
              <EmployeeCode>'.$employeeCode.'</EmployeeCode>
              <FPIndex>'.$fingerIndex.'</FPIndex>
            </DeviceCommand_EnrollFP>
          </soap:Body>
        </soap:Envelope>';

        $response = Http::withHeaders([
            'Content-Type' => 'text/xml; charset=utf-8',
            'SOAPAction' => '"http://tempuri.org/DeviceCommand_EnrollFP"'
        ])->send('POST', rtrim($organisation->ebio_url, '/') . '/webservice.asmx', [
            'body' => $payload
        ]);

        $body = $response->body();

        if (str_contains($body, '<DeviceCommand_EnrollFPResult />')) {
            return true;
        }

        preg_match('/<DeviceCommand_EnrollFPResult>(.*?)<\/DeviceCommand_EnrollFPResult>/', $body, $matches);
        $result = $matches[1] ?? '';

        $isSuccess = strtolower($result) === 'success' || (empty($result) && $result !== 'error');
        if (!$isSuccess) {
            \Illuminate\Support\Facades\Log::error("EnrollFP API failed. Response: " . $body);
        }
        return $isSuccess;
    }

    /**
     * Trigger Face Enrollment on the device (with fallback to EnrollFaceEx)
     */
    public function enrollFace(Organisation $organisation, string $serialNumber, string $employeeCode): bool
    {
        $payload = '<?xml version="1.0" encoding="utf-8"?>
        <soap:Envelope xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance" xmlns:xsd="http://www.w3.org/2001/XMLSchema" xmlns:soap="http://schemas.xmlsoap.org/soap/envelope/">
          <soap:Body>
            <DeviceCommand_EnrollFace xmlns="http://tempuri.org/">
              <UserName>'.$organisation->ebio_soap_username.'</UserName>
              <Password>'.$organisation->ebio_soap_password.'</Password>
              <DeviceSerialNumber>'.$serialNumber.'</DeviceSerialNumber>
              <EmployeeCode>'.$employeeCode.'</EmployeeCode>
            </DeviceCommand_EnrollFace>
          </soap:Body>
        </soap:Envelope>';

        $response = Http::withHeaders([
            'Content-Type' => 'text/xml; charset=utf-8',
            'SOAPAction' => '"http://tempuri.org/DeviceCommand_EnrollFace"'
        ])->send('POST', rtrim($organisation->ebio_url, '/') . '/webservice.asmx', [
            'body' => $payload
        ]);

        $body = $response->body();

        $isSuccess = false;
        if (str_contains($body, '<DeviceCommand_EnrollFaceResult />')) {
            $isSuccess = true;
        } else {
            preg_match('/<DeviceCommand_EnrollFaceResult>(.*?)<\/DeviceCommand_EnrollFaceResult>/', $body, $matches);
            $result = $matches[1] ?? '';
            $isSuccess = strtolower($result) === 'success' || (empty($result) && $result !== 'error');
        }

        if ($isSuccess) {
            return true;
        }
        
        \Illuminate\Support\Facades\Log::info("EnrollFace failed, attempting EnrollFaceEx for Employee: " . $employeeCode);

        // Fallback to EnrollFaceEx
        $payloadEx = '<?xml version="1.0" encoding="utf-8"?>
        <soap:Envelope xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance" xmlns:xsd="http://www.w3.org/2001/XMLSchema" xmlns:soap="http://schemas.xmlsoap.org/soap/envelope/">
          <soap:Body>
            <DeviceCommand_EnrollFaceEx xmlns="http://tempuri.org/">
              <UserName>'.$organisation->ebio_soap_username.'</UserName>
              <Password>'.$organisation->ebio_soap_password.'</Password>
              <DeviceSerialNumber>'.$serialNumber.'</DeviceSerialNumber>
              <EmployeeCode>'.$employeeCode.'</EmployeeCode>
            </DeviceCommand_EnrollFaceEx>
          </soap:Body>
        </soap:Envelope>';

        $responseEx = Http::withHeaders([
            'Content-Type' => 'text/xml; charset=utf-8',
            'SOAPAction' => '"http://tempuri.org/DeviceCommand_EnrollFaceEx"'
        ])->send('POST', rtrim($organisation->ebio_url, '/') . '/webservice.asmx', [
            'body' => $payloadEx
        ]);

        $bodyEx = $responseEx->body();

        if (str_contains($bodyEx, '<DeviceCommand_EnrollFaceExResult />')) {
            return true;
        }

        preg_match('/<DeviceCommand_EnrollFaceExResult>(.*?)<\/DeviceCommand_EnrollFaceExResult>/', $bodyEx, $matches);
        $resultEx = $matches[1] ?? '';

        $isSuccessEx = strtolower($resultEx) === 'success' || (empty($resultEx) && $resultEx !== 'error');
        if (!$isSuccessEx) {
            \Illuminate\Support\Facades\Log::error("EnrollFaceEx API failed. Response: " . $bodyEx);
        }
        return $isSuccessEx;
    }

    /**
     * Helper to fetch users from external service.
     */
    public function unlockDoor(Organisation $organisation, string $serialNumber): bool
    {
        if (empty($organisation->ebio_url) || empty($organisation->ebio_soap_username)) {
            throw new \Exception("eBioServer SOAP credentials are not configured for this organisation.");
        }

        $url = rtrim($organisation->ebio_url, '/') . '/webservice.asmx';
        
        $xml = '<?xml version="1.0" encoding="utf-8"?>
        <soap:Envelope xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance" xmlns:xsd="http://www.w3.org/2001/XMLSchema" xmlns:soap="http://schemas.xmlsoap.org/soap/envelope/">
          <soap:Body>
            <DeviceCommand_UnlockDoor xmlns="http://tempuri.org/">
              <UserName>'.$organisation->ebio_soap_username.'</UserName>
              <Password>'.$organisation->ebio_soap_password.'</Password>
              <DeviceSerialNumber>'.$serialNumber.'</DeviceSerialNumber>
            </DeviceCommand_UnlockDoor>
          </soap:Body>
        </soap:Envelope>';

        $response = Http::withHeaders([
            'Content-Type' => 'text/xml; charset=utf-8',
            'SOAPAction' => '"http://tempuri.org/DeviceCommand_UnlockDoor"'
        ])->send('POST', $url, [
            'body' => $xml
        ]);

        if (!$response->successful()) {
            return false;
        }

        $body = $response->body();

        if (str_contains($body, '<DeviceCommand_UnlockDoorResult />')) {
            return true;
        }

        preg_match('/<DeviceCommand_UnlockDoorResult>(.*?)<\/DeviceCommand_UnlockDoorResult>/', $body, $matches);
        $result = $matches[1] ?? '';

        return strtolower($result) === 'success' || empty($result) && $result !== 'error';
    }

    /**
     * Add or update device on eBioServer.
     */
    public function addDevice(Organisation $organisation, array $data): bool
    {
        if (empty($organisation->ebio_url) || empty($organisation->ebio_soap_username)) {
            throw new \Exception("eBioServer SOAP credentials are not configured for this organisation.");
        }

        $url = rtrim($organisation->ebio_url, '/') . '/webservice.asmx';
        
        $xml = '<?xml version="1.0" encoding="utf-8"?>
        <soap:Envelope xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance" xmlns:xsd="http://www.w3.org/2001/XMLSchema" xmlns:soap="http://schemas.xmlsoap.org/soap/envelope/">
          <soap:Body>
            <UpdateDevice xmlns="http://tempuri.org/">
              <UserName>'.$organisation->ebio_soap_username.'</UserName>
              <Password>'.$organisation->ebio_soap_password.'</Password>
              <DeviceSerialNumber>'.$data['serial_number'].'</DeviceSerialNumber>
              <DeviceName>'.htmlspecialchars($data['name']).'</DeviceName>
              <DeviceDiretion>'.($data['direction'] ?? '').'</DeviceDiretion>
              <DeviceType>'.($data['device_type'] ?? '').'</DeviceType>
              <TimeZone>'.($data['time_zone'] ?? '').'</TimeZone>
              <DeviceActivationCode>'.($data['activation_code'] ?? '').'</DeviceActivationCode>
              <Location>'.htmlspecialchars($data['location'] ?? '').'</Location>
              <IsAttendanceDevice>'.($data['is_attendance_device'] ?? 'true').'</IsAttendanceDevice>
            </UpdateDevice>
          </soap:Body>
        </soap:Envelope>';

        $response = Http::withHeaders([
            'Content-Type' => 'text/xml; charset=utf-8',
            'SOAPAction' => '"http://tempuri.org/UpdateDevice"'
        ])->send('POST', $url, [
            'body' => $xml
        ]);

        if (!$response->successful()) {
            return false;
        }

        preg_match('/<UpdateDeviceResult>(.*?)<\/UpdateDeviceResult>/', $response->body(), $matches);
        $result = $matches[1] ?? '';

        return strtolower($result) === 'success';
    }
}

