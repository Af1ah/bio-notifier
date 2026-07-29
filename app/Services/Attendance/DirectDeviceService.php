<?php

namespace App\Services\Attendance;

use App\Models\Device;
use App\Models\User;
use Illuminate\Support\Facades\Log;
use Rats\Zkteco\Lib\ZKTeco;

class DirectDeviceService
{
    /**
     * Connects to a device via UDP port 4370 (ZKTeco Protocol), fetches all users,
     * checks their registered fingerprints, and syncs them to the database.
     *
     * @param Device $device
     * @return array Result of the sync operation
     */
    public function syncUsersFromDevice(Device $device): array
    {
        if (empty($device->ip_address)) {
            return ['status' => false, 'message' => 'Device has no IP address configured.'];
        }

        $zk = new ZKTeco($device->ip_address, 4370);
        
        if (!$zk->connect()) {
            Log::error("Failed to connect to device via ZKLib", ['device_id' => $device->id, 'ip' => $device->ip_address]);
            return ['status' => false, 'message' => "Could not connect to device at {$device->ip_address}:4370."];
        }

        try {
            $deviceUsers = $zk->getUser();
            $syncedCount = 0;

            if (is_array($deviceUsers)) {
                foreach ($deviceUsers as $uid => $zkUser) {
                    $pin = (string)$zkUser['userid'];
                    
                    // ZKLib sometimes returns empty user slots or corrupted names. Just make sure pin is valid.
                    if (empty($pin)) continue;

                    $name = $zkUser['name'];
                    $card = $zkUser['cardno'] ?? null;
                    $role = $zkUser['role'] ?? 0;

                    // Fetch fingerprints for this user UID
                    $fingerprints = [];
                    $deviceFingers = $zk->getFingerprint($uid);
                    
                    if (is_array($deviceFingers)) {
                        foreach ($deviceFingers as $fingerId => $fingerData) {
                            $fingerprints[$fingerId] = [
                                'size' => strlen($fingerData),
                                'valid' => 1,
                                'template' => base64_encode($fingerData),
                            ];
                        }
                    }

                    // For the user model class, it depends on context (Tenant). Usually App\Models\User is the tenant model.
                    $userModelClass = config('zkteco-adms.models.user', User::class);

                    $existingUser = $userModelClass::where('pin', $pin)->first();
                    $updateData = [
                        'name' => $name ?: ($existingUser ? $existingUser->name : "User {$pin}"),
                        'card_number' => ($card === '0' || $card === '0000000000' || (int)$card === 0) ? null : $card,
                        'privilege' => $role,
                    ];

                    if (!empty($fingerprints)) {
                        $updateData['fingerprints'] = $fingerprints;
                    }

                    $userModelClass::updateOrCreate(
                        ['pin' => $pin],
                        $updateData
                    );

                    $syncedCount++;
                }
            }

            $zk->disconnect();
            return ['status' => true, 'message' => "Successfully synced {$syncedCount} users from the device."];

        } catch (\Exception $e) {
            $zk->disconnect();
            Log::error("Error syncing users via ZKLib", [
                'device_id' => $device->id,
                'error' => $e->getMessage()
            ]);
            return ['status' => false, 'message' => "Error while syncing: " . $e->getMessage()];
        }
    }

    /**
     * Connects to a device via UDP port 4370 (ZKTeco Protocol) and pushes the selected users,
     * including their fingerprints, RFID cards, and passwords.
     *
     * @param Device $device
     * @param iterable $users
     * @return array Result of the push operation
     */
    public function pushUsersToDevice(Device $device, iterable $users): array
    {
        if (empty($device->ip_address)) {
            return ['status' => false, 'message' => 'Device has no IP address configured.'];
        }

        $zk = new ZKTeco($device->ip_address, 4370);
        
        if (!$zk->connect()) {
            Log::error("Failed to connect to device via ZKLib for push", ['device_id' => $device->id]);
            return ['status' => false, 'message' => "Could not connect to device at {$device->ip_address}:4370."];
        }

        try {
            $deviceUsers = $zk->getUser();
            $deviceUsersByPin = [];
            $maxUid = 0;
            if (is_array($deviceUsers)) {
                foreach ($deviceUsers as $uid => $zkUser) {
                    $pin = (string)$zkUser['userid'];
                    $deviceUsersByPin[$pin] = $uid;
                    if ($uid > $maxUid) {
                        $maxUid = $uid;
                    }
                }
            }

            $syncedCount = 0;
            foreach ($users as $user) {
                $pin = (string)$user->pin;
                if (isset($deviceUsersByPin[$pin])) {
                    $uid = $deviceUsersByPin[$pin];
                } else {
                    $maxUid++;
                    $uid = $maxUid;
                }

                $role = (int)$user->privilege;
                $card = $user->card_number ? (int)$user->card_number : 0;
                $password = $user->device_password ?? '';
                
                // Set User Profile
                $zk->setUser($uid, $pin, $user->name, $password, $role, $card);

                // Set Fingerprints if any
                if (!empty($user->fingerprints) && is_array($user->fingerprints)) {
                    $fingerDataArray = [];
                    foreach ($user->fingerprints as $fingerId => $data) {
                        if (isset($data['template'])) {
                            $fingerDataArray[$fingerId] = base64_decode($data['template']);
                        }
                    }
                    if (!empty($fingerDataArray)) {
                        $zk->setFingerprint($uid, $fingerDataArray);
                    }
                }
                
                $syncedCount++;
            }

            $zk->disconnect();
            return ['status' => true, 'message' => "Successfully pushed {$syncedCount} users to the device."];
        } catch (\Exception $e) {
            $zk->disconnect();
            Log::error("Error pushing users via ZKLib", ['error' => $e->getMessage()]);
            return ['status' => false, 'message' => "Error while pushing: " . $e->getMessage()];
        }
    }

    /**
     * Test local network connection to a ZKTeco device.
     *
     * @param Device $device
     * @return array
     */
    public function testConnection(Device $device): array
    {
        if (empty($device->ip_address)) {
            return ['status' => false, 'message' => 'Device has no IP address configured. Please edit the device and set its local IP.'];
        }

        $zk = new ZKTeco($device->ip_address, 4370);
        
        if (!$zk->connect()) {
            return ['status' => false, 'message' => "Could not connect to device at {$device->ip_address}:4370."];
        }

        $deviceName = $zk->deviceName();
        $zk->disconnect();

        return ['status' => true, 'message' => "Successfully connected to device. Device Name: {$deviceName}"];
    }

    /**
     * Sync attendance logs from a local ZKTeco device.
     *
     * @param Device $device
     * @return array
     */
    public function syncAttendanceLogs(Device $device): array
    {
        if (empty($device->ip_address)) {
            return ['status' => false, 'message' => 'Device has no IP address configured. Please edit the device and set its local IP.'];
        }

        $zk = new ZKTeco($device->ip_address, 4370);
        
        if (!$zk->connect()) {
            return ['status' => false, 'message' => "Could not connect to device at {$device->ip_address}:4370."];
        }

        try {
            $attendanceLogs = $zk->getAttendance();
            $zk->disconnect();

            if (!is_array($attendanceLogs) || empty($attendanceLogs)) {
                return ['status' => true, 'message' => 'No attendance logs found on the device.'];
            }

            $syncedCount = 0;
            $userModelClass = config('zkteco-adms.models.user', User::class);
            $attendanceLogModelClass = config('zkteco-adms.models.attendance_log', \App\Models\AttendanceLog::class);

            foreach ($attendanceLogs as $log) {
                $pin = (string)$log['id'];
                $timestamp = $log['timestamp']; 
                $state = $log['state'] ?? 1; 
                $type = $log['type'] ?? 1; 

                $user = $userModelClass::where('pin', $pin)->first();
                
                if ($user) {
                    $attendanceLogModelClass::firstOrCreate([
                        'user_id' => $user->id,
                        'device_id' => $device->id,
                        'punch_time' => $timestamp,
                    ], [
                        'punch_state' => $state,
                        'verify_type' => $type,
                    ]);
                    $syncedCount++;
                }
            }

            return ['status' => true, 'message' => "Successfully synced {$syncedCount} attendance logs."];

        } catch (\Exception $e) {
            $zk->disconnect();
            Log::error("Error syncing attendance logs via ZKLib", ['error' => $e->getMessage()]);
            return ['status' => false, 'message' => "Error while syncing logs: " . $e->getMessage()];
        }
    }
}
