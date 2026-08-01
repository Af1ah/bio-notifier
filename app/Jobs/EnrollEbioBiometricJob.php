<?php

namespace App\Jobs;

use App\Models\Organisation;
use App\Models\User;
use App\Models\Device;
use App\Services\EbioSoapService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

class EnrollEbioBiometricJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public $organisation;
    public $userId;
    public $deviceId;
    public $type;
    public $fingerIndex;

    public function __construct(Organisation $organisation, int $userId, int $deviceId, string $type, ?int $fingerIndex = null)
    {
        $this->organisation = $organisation;
        $this->userId = $userId;
        $this->deviceId = $deviceId;
        $this->type = $type;
        $this->fingerIndex = $fingerIndex;
    }

    public function handle(EbioSoapService $service): void
    {
        tenancy()->initialize($this->organisation);
        $user = User::find($this->userId);
        $device = Device::find($this->deviceId);
        
        if ($user && $device) {
            $commandType = $this->type === 'finger' ? 'enroll_finger' : 'enroll_face';
            $details = $this->type === 'finger' ? "Finger Index: {$this->fingerIndex}" : 'Face';
            $commandModel = \App\Models\DeviceCommand::create([
                'device_id' => $device->id,
                'command_type' => $commandType,
                'command_content' => "eBioServer SOAP Command: {$commandType} (Employee: {$user->pin}, {$details})",
                'status' => 'sent',
            ]);

            try {
                if ($this->type === 'finger') {
                    $success = $service->enrollFingerprint($this->organisation, $device->serial_number, $user->pin, $this->fingerIndex);
                } else {
                    $success = $service->enrollFace($this->organisation, $device->serial_number, $user->pin);
                }
                
                if ($success) {
                    $commandModel->markAsAcknowledged('Success');
                } else {
                    $commandModel->markAsFailed('API returned false');
                }
            } catch (\Exception $e) {
                $commandModel->markAsFailed($e->getMessage());
                Log::error("Failed to enroll biometric on eBioServer: " . $e->getMessage());
                throw $e;
            }
        }
    }
}
