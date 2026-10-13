<?php

namespace App\Console\Commands;

use App\Models\Organisation;
use Illuminate\Console\Command;

class RotateEbioWebhookToken extends Command
{
    protected $signature = 'ebio:rotate-webhook-token {tenant : Organisation ID or shortname}';

    protected $description = 'Rotate a tenant webhook credential; update eBio with the new URL shown in Manage Organisation';

    public function handle(): int
    {
        $tenant = Organisation::where('id', $this->argument('tenant'))->orWhere('shortname', $this->argument('tenant'))->firstOrFail();
        $tenant->update(['ebio_webhook_token' => bin2hex(random_bytes(32))]);
        $this->info('Credential rotated. Copy the new URL from Manage Organisation into eBio. Set Webhook Response to Success.');

        return self::SUCCESS;
    }
}
