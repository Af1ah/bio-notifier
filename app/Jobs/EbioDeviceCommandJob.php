<?php

namespace App\Jobs;

use App\Models\Organisation;
use App\Services\EbioSoapService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

class EbioDeviceCommandJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public $organisation;
    public $serialNumber;
    public $commandType;
    public $commandId;

    public function __construct(Organisation $organisation, string $serialNumber, string $commandType, ?int $commandId = null)
    {
        $this->organisation = $organisation;
        $this->serialNumber = $serialNumber;
        $this->commandType = $commandType;
        $this->commandId = $commandId;
    }

    public function handle(EbioSoapService $service): void
    {
        tenancy()->initialize($this->organisation);
        $commandModel = null;
        if ($this->commandId) {
            $commandModel = \App\Models\DeviceCommand::find($this->commandId);
            if ($commandModel) $commandModel->markAsSent();
        }

        try {
            $success = false;
            switch ($this->commandType) {
                case 'reboot':
                    $success = $service->rebootDevice($this->organisation, $this->serialNumber);
                    break;
                case 'clear_logs':
                    $success = $service->clearDeviceLogs($this->organisation, $this->serialNumber);
                    break;
                case 'reset_transaction_stamp':
                    $success = $service->resetTransactionStamp($this->organisation, $this->serialNumber);
                    break;
                case 'reset_op_stamp':
                    $success = $service->resetOPStamp($this->organisation, $this->serialNumber);
                    break;
                case 'unlock_door':
                    $success = $service->unlockDoor($this->organisation, $this->serialNumber);
                    break;
                default:
                    Log::warning("Unknown device command type: {$this->commandType}");
                    break;
            }

            if ($commandModel) {
                if ($success) {
                    $commandModel->markAsAcknowledged('Success');
                } else {
                    $commandModel->markAsFailed('API returned false');
                }
            }
        } catch (\Exception $e) {
            Log::error("Failed to execute device command {$this->commandType} on {$this->serialNumber}: " . $e->getMessage());
            if ($commandModel) {
                $commandModel->markAsFailed($e->getMessage());
            }
            $this->fail($e);
        }
    }
}
