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
    public function deleteUser(Organisation $organisation, string $employeeCode): bool
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
              <EmployeeCode>'.$employeeCode.'</EmployeeCode>
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

        return strtolower($result) === 'success';
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

        preg_match('/<DeviceCommand_RebootResult>(.*?)<\/DeviceCommand_RebootResult>/', $response->body(), $matches);
        $result = $matches[1] ?? '';

        return strtolower($result) === 'success';
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

        preg_match('/<DeviceCommand_ClearLogsResult>(.*?)<\/DeviceCommand_ClearLogsResult>/', $response->body(), $matches);
        $result = $matches[1] ?? '';

        return strtolower($result) === 'success';
    }

    /**
     * Force fetch logs from device (Reset transaction stamp)
     */
    public function forceFetchDeviceLogs(Organisation $organisation, string $serialNumber): bool
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

        preg_match('/<DeviceCommand_ResetTransactionStampResult>(.*?)<\/DeviceCommand_ResetTransactionStampResult>/', $response->body(), $matches);
        $result = $matches[1] ?? '';

        return strtolower($result) === 'success';
    }
}
