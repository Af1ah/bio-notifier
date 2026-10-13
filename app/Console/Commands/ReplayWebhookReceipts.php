<?php

namespace App\Console\Commands;

use App\Jobs\ProcessEbioWebhookJob;
use App\Models\Organisation;
use App\Models\WebhookReceipt;
use Illuminate\Console\Command;

class ReplayWebhookReceipts extends Command
{
    protected $signature = 'ebio:replay-receipts {--failed : Also retry failed receipts after investigating their cause}';

    protected $description = 'Recover persisted webhook batches without resetting the vendor sync stamp';

    public function handle(): int
    {
        WebhookReceipt::where(function ($query) {
            $query->where('state', 'pending');
            if ($this->option('failed')) {
                $query->orWhere('state', 'failed');
            }
        })->orderBy('id')->chunkById(100, function ($receipts) {
            foreach ($receipts as $receipt) {
                $tenant = Organisation::find($receipt->organisation_id);
                if (! $tenant) {
                    continue;
                }
                // Claim pending work before dispatch; interrupted claims are
                // recovered below after a bounded lease.
                if (! WebhookReceipt::whereKey($receipt->id)->where('state', $receipt->state)->update(['state' => 'queued', 'updated_at' => now()])) {
                    continue;
                }
                try {
                    ProcessEbioWebhookJob::dispatch($tenant, [], $receipt->id);
                } catch (\Throwable $exception) {
                    $receipt->update(['state' => 'pending', 'last_error' => 'Queue dispatch failed.']);
                }
            }
        });
        WebhookReceipt::whereIn('state', ['queued', 'processing'])->where('updated_at', '<', now()->subMinutes(10))->update(['state' => 'pending']);
        // Only resolved receipts; immutable tenant punches and failed/pending
        // evidence are never pruned here.
        WebhookReceipt::where('state', 'processed')->where('updated_at', '<', now()->subDays(30))->delete();
        $this->info('Receipt recovery dispatch completed. Failed receipts require --failed.');

        return self::SUCCESS;
    }
}
