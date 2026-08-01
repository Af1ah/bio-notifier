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

class BlockUnblockEbioUserJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public $organisation;
    public $userId;
    public $location;
    public $blockUser;

    public function __construct(Organisation $organisation, int $userId, string $location = '', bool $blockUser = true)
    {
        $this->organisation = $organisation;
        $this->userId = $userId;
        $this->location = $location;
        $this->blockUser = $blockUser;
    }

    public function handle(EbioSoapService $service): void
    {
        tenancy()->initialize($this->organisation);
        $user = User::find($this->userId);
        
        if ($user) {
            try {
                // Determine which devices to apply to
                $query = Device::query();
                if (!empty($this->location)) {
                    $locations = explode(',', $this->location);
                    $query->where(function($q) use ($locations) {
                        foreach ($locations as $loc) {
                            $q->orWhere('options->location', $loc);
                        }
                    });
                }
                $devices = $query->get();
                
                foreach ($devices as $device) {
                    $commandType = $this->blockUser ? 'block_user' : 'unblock_user';
                    $commandModel = \App\Models\DeviceCommand::create([
                        'device_id' => $device->id,
                        'command_type' => $commandType,
                        'command_content' => "eBioServer SOAP Command: {$commandType} (Employee: {$user->pin})",
                        'status' => 'sent',
                    ]);

                    try {
                        $success = $service->blockUserFromDoor($this->organisation, $device->serial_number, $user->pin, $this->blockUser);
                        
                        if ($success) {
                            $commandModel->markAsAcknowledged('Success');
                            
                            // Update the user's local blocked_devices state
                            $blockedDevices = $user->blocked_devices ?? [];
                            if ($this->blockUser) {
                                if (!in_array($device->serial_number, $blockedDevices)) {
                                    $blockedDevices[] = $device->serial_number;
                                }
                            } else {
                                $blockedDevices = array_filter($blockedDevices, fn($sn) => $sn !== $device->serial_number);
                            }
                            $user->update(['blocked_devices' => array_values($blockedDevices)]);
                            
                        } else {
                            $commandModel->markAsFailed('API returned false');
                        }
                    } catch (\Exception $e) {
                        $commandModel->markAsFailed($e->getMessage());
                        throw $e;
                    }
                }
            } catch (\Exception $e) {
                Log::error("Failed to block/unblock user on eBioServer: " . $e->getMessage());
                $this->fail($e);
            }
        }
    }
}
