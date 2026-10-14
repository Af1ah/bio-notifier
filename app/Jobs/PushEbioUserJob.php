<?php

namespace App\Jobs;

use App\Models\Organisation;
use App\Models\User;
use App\Services\EbioSoapService;
use App\Services\Tenancy\TenantContext;
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
        TenantContext::run($this->organisation, function () use ($service): void {
            $user = User::find($this->userId);

            if ($user) {
                try {
                    if (! $service->pushUser($this->organisation, $user, $this->location)) {
                        throw new \RuntimeException('eBioServer rejected the employee operation.');
                    }
                } catch (\Exception $e) {
                    Log::error('Failed to push user to eBioServer: '.$e->getMessage());
                    $this->fail($e);
                }
            }
        });
    }
}
