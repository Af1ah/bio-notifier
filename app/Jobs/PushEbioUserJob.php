<?php

namespace App\Jobs;

use App\Models\Organisation;
use App\Models\User;
use App\Services\EbioSoapService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

class PushEbioUserJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public $organisation;
    public $userId;
    public $location;

    public function __construct(Organisation $organisation, int $userId, string $location = '')
    {
        $this->organisation = $organisation;
        $this->userId = $userId;
        $this->location = $location;
    }

    public function handle(EbioSoapService $service): void
    {
        tenancy()->initialize($this->organisation);
        $user = User::find($this->userId);
        
        if ($user) {
            try {
                $service->pushUser($this->organisation, $user, $this->location);
            } catch (\Exception $e) {
                Log::error("Failed to push user to eBioServer: " . $e->getMessage());
                $this->fail($e);
            }
        }
    }
}
