<?php

namespace App\Services\Attendance;

use App\Jobs\ProcessHikvisionCommand;
use App\Jobs\ProcessMatrixCommand;
use App\Models\Device;
use App\Models\DeviceCommand;

class DeviceCommandBuilder
{
    public function info(Device $device): DeviceCommand
    {
        return $this->createCommand($device, 'INFO', 'INFO');
    }

    public function reboot(Device $device): DeviceCommand
    {
        return $this->createCommand($device, 'REBOOT', 'REBOOT');
    }

    public function clearAttendanceLogs(Device $device): DeviceCommand
    {
        return $this->createCommand($device, 'CLEAR', 'CLEAR LOG');
    }

    public function clearAllData(Device $device): DeviceCommand
    {
        return $this->createCommand($device, 'CLEAR', 'CLEAR DATA');
    }

    public function clearUsers(Device $device): DeviceCommand
    {
        return $this->createCommand($device, 'CLEAR', 'CLEAR USER');
    }

    public function addUser(Device $device, array $userData): DeviceCommand
    {
        $fields = [
            "PIN={$userData['pin']}",
            'Name='.($userData['name'] ?? ''),
            'Card='.($userData['card'] ?? ''),
            'Pri='.($userData['privilege'] ?? 0),
            'Passwd='.($userData['password'] ?? ''),
            'Grp='.($userData['group'] ?? 1),
        ];

        if ($device->vendor === 'matrix') {
            $fields[] = 'Active=' . (($userData['is_enabled'] ?? true) ? '1' : '0');
        }

        $content = 'DATA USER '.implode("\t", $fields);

        return $this->createCommand($device, 'DATA', $content);
    }

    public function addFingerprint(Device $device, string $pin, int $fid, string $template): DeviceCommand
    {
        $size = strlen($template);
        $content = "DATA FINGERTMP PIN={$pin}\tFID={$fid}\tSize={$size}\tValid=1\tTMP={$template}";

        return $this->createCommand($device, 'DATA', $content);
    }

    public function deleteUser(Device $device, string $pin): DeviceCommand
    {
        return $this->createCommand($device, 'DATA', "DATA DEL_USER PIN={$pin}");
    }

    public function queryUser(Device $device, string $pin): DeviceCommand
    {
        return $this->createCommand($device, 'DATA', "DATA QUERY USERINFO PIN={$pin}");
    }

    public function queryAllUsers(Device $device): DeviceCommand
    {
        return $this->createCommand($device, 'DATA', 'DATA QUERY USERINFO');
    }

    public function queryAllFingerprints(Device $device): DeviceCommand
    {
        return $this->createCommand($device, 'DATA', 'DATA QUERY FINGERTMP');
    }

    public function queryAttendanceLogs(Device $device, ?string $startTime = null, ?string $endTime = null): DeviceCommand
    {
        $content = 'DATA QUERY ATTLOG';
        if ($startTime && $endTime) {
            $content .= " StartTime={$startTime}\tEndTime={$endTime}";
        }

        return $this->createCommand($device, 'DATA', $content);
    }

    public function checkConnection(Device $device): DeviceCommand
    {
        return $this->createCommand($device, 'CHECK', 'CHECK');
    }

    public function syncTime(Device $device): DeviceCommand
    {
        $now = now()->format('Y-m-d H:i:s');

        return $this->createCommand($device, 'INFO', "SET OPTIONS ServerLocalTime={$now}");
    }

    public function unlockDoor(Device $device): DeviceCommand
    {
        return $this->createCommand($device, 'DOOR_UNLOCK', 'DOOR_UNLOCK');
    }

    public function lockDoor(Device $device): DeviceCommand
    {
        return $this->createCommand($device, 'DOOR_LOCK', 'DOOR_LOCK');
    }

    public function normalizeDoor(Device $device): DeviceCommand
    {
        return $this->createCommand($device, 'DOOR_NORMALIZE', 'DOOR_NORMALIZE');
    }

    public function enrollBiometric(Device $device, string $pin, string $type, array $extra = []): DeviceCommand
    {
        $payload = [
            'pin' => $pin,
            'type' => $type,
            'extra' => $extra,
        ];

        return $this->createCommand($device, 'ENROLL_BIOMETRIC', 'ENROLL_BIOMETRIC ' . json_encode($payload));
    }

    public function enableEnrollment(Device $device): DeviceCommand
    {
        return $this->createCommand($device, 'ENABLE_ENROLLMENT', 'ENABLE_ENROLLMENT');
    }

    protected function createCommand(Device $device, string $type, string $content): DeviceCommand
    {
        $modelClass = config('zkteco-adms.models.device_command', DeviceCommand::class);

        $command = $modelClass::create([
            'device_id' => $device->id,
            'command_type' => $type,
            'command_content' => $content,
            'status' => 'pending',
        ]);

        if ($device->vendor === 'hikvision') {
            ProcessHikvisionCommand::dispatchSync($command->id, (string) tenant('id'));
            $command->refresh();
        } elseif ($device->vendor === 'matrix') {
            ProcessMatrixCommand::dispatchSync($command->id, (string) tenant('id'));
            $command->refresh();
        }

        return $command;
    }
}
