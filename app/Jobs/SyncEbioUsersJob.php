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

class SyncEbioUsersJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public $organisation;

    public function __construct(Organisation $organisation)
    {
        $this->organisation = $organisation;
    }

    public function handle(EbioSoapService $service): void
    {
        try {
            $service->syncUsers($this->organisation);
        } catch (\Exception $e) {
            Log::error("Failed to sync users for organisation {$this->organisation->id}: " . $e->getMessage());
            $this->fail($e);
        }
    }
}
