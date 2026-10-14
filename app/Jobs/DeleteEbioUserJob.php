<?php

namespace App\Jobs;

use App\Models\Organisation;
use App\Services\EbioSoapService;
use App\Services\Tenancy\TenantContext;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

class DeleteEbioUserJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public $organisation;

    public $employeeCode;

    public $location;

    public function __construct(Organisation $organisation, string $employeeCode, string $location = '')
    {
        $this->organisation = $organisation;
        $this->employeeCode = $employeeCode;
        $this->location = $location;
    }

    public function handle(EbioSoapService $service): void
    {
        TenantContext::run($this->organisation, function () use ($service): void {
            try {
                if (! $service->deleteUser($this->organisation, $this->employeeCode, $this->location)) {
                    throw new \RuntimeException('eBioServer rejected the employee operation.');
                }
            } catch (\Exception $e) {
                Log::error("Failed to delete user {$this->employeeCode} from eBioServer: ".$e->getMessage());
                $this->fail($e);
            }
        });
    }
}
