<?php

namespace App\Services\Attendance;

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
            'Name=' . ($userData['name'] ?? ''),
            'Card=' . ($userData['card'] ?? ''),
            'Pri=' . ($userData['privilege'] ?? 0),
            'Passwd=' . ($userData['password'] ?? ''),
            'Grp=' . ($userData['group'] ?? 1),
        ];

        $content = 'DATA USER ' . implode("\t", $fields);

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
        return $this->createCommand($device, 'DATA', "DATA QUERY USERINFO");
    }

    public function queryAllFingerprints(Device $device): DeviceCommand
    {
        return $this->createCommand($device, 'DATA', "DATA QUERY FINGERTMP");
    }

    public function queryAttendanceLogs(Device $device, string $startTime = null, string $endTime = null): DeviceCommand
    {
        $content = "DATA QUERY ATTLOG";
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

    protected function createCommand(Device $device, string $type, string $content): DeviceCommand
    {
        $modelClass = config('zkteco-adms.models.device_command', DeviceCommand::class);

        $command = $modelClass::create([
            'device_id' => $device->id,
            'command_type' => $type,
            'command_content' => $content,
            'status' => 'pending',
        ]);
        
        

        return $command;
    }
}
