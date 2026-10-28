<?php

namespace App\Jobs\Gateway;

use App\Models\GatewayIngressReceipt;
use App\Models\Organisation;
use App\Services\Devices\Gateway\ReceiptApplier;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class ApplyGatewayReceipt implements ShouldQueue
{
    use Queueable;

    public int $tries = 5;

    public int $timeout = 60;

    public function __construct(public string $receiptId) {}

    public function signalId(): string
    {
        return 'receipt:'.$this->receiptId;
    }

    public function backoff(): array
    {
        return [5, 30, 120, 300];
    }

    public function handle(ReceiptApplier $applier): void
    {
        if (tenancy()->initialized) {
            tenancy()->end();
        }
        $token = (string) Str::uuid();
        $claimed = GatewayIngressReceipt::whereKey($this->receiptId)->where('attempts', '<', 5)
            ->where(function ($q) {
                $q->whereIn('state', ['pending', 'retrying'])->orWhere(fn ($q) => $q->where('state', 'processing')->where('leased_until', '<', now()));
            })
            ->where(fn ($q) => $q->whereNull('next_attempt_at')->orWhere('next_attempt_at', '<=', now()))
            ->update(['state' => 'processing', 'lease_token' => $token, 'leased_until' => now()->addSeconds(180), 'attempts' => DB::raw('attempts + 1')]);
        if (! $claimed) {
            // Recover the final projection if the worker stopped after central acceptance.
            $applied = GatewayIngressReceipt::whereKey($this->receiptId)->where('state', 'applied')->first();
            if ($applied) {
                try {
                    tenancy()->initialize(Organisation::findOrFail($applied->organisation_id));
                    app(\App\Services\Devices\Gateway\CommandDataApplication::class)->applied($applied);
                } finally { tenancy()->end(); }
            }
            return;
        }
        $receipt = GatewayIngressReceipt::findOrFail($this->receiptId);
        try {
            // Accepted evidence keeps its original owner even if the provider is later switched.
            tenancy()->initialize(Organisation::findOrFail($receipt->organisation_id));
            $applier->apply($receipt);
            foreach (DB::table('gateway_domain_effects')->where('receipt_id', $receipt->id)->where('state', 'pending')->get() as $effect) {
                try {
                    ApplyDomainEffect::dispatch($receipt->organisation_id, $effect->id)->onConnection('gateway')
                        ->onQueue($effect->type === 'notification' ? 'exports-notifications' : 'attendance-live');
                } catch (\Throwable $exception) {
                    report($exception);
                }
            }
            GatewayIngressReceipt::whereKey($receipt->id)->where('lease_token', $token)->update(['state' => 'applied', 'lease_token' => null, 'leased_until' => null, 'error_code' => null]);
            app(\App\Services\Devices\Gateway\CommandDataApplication::class)->applied($receipt);
        } catch (\Throwable $exception) {
            tenancy()->end();
            GatewayIngressReceipt::whereKey($receipt->id)->where('lease_token', $token)->update([
                'state' => $receipt->attempts >= 5 ? 'parked' : 'retrying', 'lease_token' => null, 'leased_until' => null,
                'next_attempt_at' => now()->addSeconds([5, 30, 120, 300, 300][min($receipt->attempts - 1, 4)]), 'error_code' => 'application_failed',
            ]);
            throw $exception;
        } finally {
            tenancy()->end();
        }
    }
}
