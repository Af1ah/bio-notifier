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

    public function __construct(Organisation $organisation, string $serialNumber, string $commandType)
    {
        $this->organisation = $organisation;
        $this->serialNumber = $serialNumber;
        $this->commandType = $commandType;
    }

    public function handle(EbioSoapService $service): void
    {
        try {
            switch ($this->commandType) {
                case 'reboot':
                    $service->rebootDevice($this->organisation, $this->serialNumber);
                    break;
                case 'clear_logs':
                    $service->clearDeviceLogs($this->organisation, $this->serialNumber);
                    break;
                case 'force_fetch_logs':
                    $service->forceFetchDeviceLogs($this->organisation, $this->serialNumber);
                    break;
                default:
                    Log::warning("Unknown device command type: {$this->commandType}");
                    break;
            }
        } catch (\Exception $e) {
            Log::error("Failed to execute device command {$this->commandType} on {$this->serialNumber}: " . $e->getMessage());
            $this->fail($e);
        }
    }
}
