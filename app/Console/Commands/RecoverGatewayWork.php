<?php

namespace App\Console\Commands;

use App\Jobs\Gateway\ApplyDomainEffect;
use App\Jobs\Gateway\ApplyGatewayReceipt;
use App\Jobs\Gateway\SubmitGatewayCommand;
use App\Jobs\Gateway\SyncGatewayBinding;
use App\Models\GatewayCommandSubmission;
use App\Models\GatewayDeviceBinding;
use App\Models\GatewayIngressReceipt;
use App\Models\Organisation;
use App\Services\Devices\Gateway\GatewayCommands;
use App\Services\Devices\Gateway\GatewayMonitor;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class RecoverGatewayWork extends Command
{
    protected $signature = 'gateway:recover {--limit=100 : Maximum work items per lane}';

    protected $description = 'Recover durable gateway receipts, submissions and tenant domain effects';

    public function handle(GatewayCommands $commands): int
    {
        if (tenancy()->initialized) {
            tenancy()->end();
        }
        GatewayMonitor::beat('scheduler', 'gateway:recovery');
        $limit = min(500, max(1, (int) $this->option('limit')));
        // Expired final-attempt owners must become visible rather than remain stuck processing.
        GatewayIngressReceipt::where('state', 'processing')->where('attempts', '>=', 5)->where('leased_until', '<', now())->update(['state' => 'parked', 'error_code' => 'lease_exhausted']);
        GatewayCommandSubmission::where('state', 'submitting')->where('attempts', '>=', 5)->where('leased_until', '<', now())->update(['state' => 'parked', 'error_code' => 'lease_exhausted']);
        foreach (GatewayIngressReceipt::where(fn ($q) => $q->whereIn('state', ['pending', 'retrying'])->orWhere(fn ($q) => $q->where('state', 'processing')->where('leased_until', '<', now())))
            ->where(fn ($q) => $q->whereNull('next_attempt_at')->orWhere('next_attempt_at', '<=', now()))->orderBy('created_at')->limit($limit)->get() as $receipt) {
            ApplyGatewayReceipt::dispatch($receipt->id)->onConnection('gateway')->onQueue('device-ingest');
        }
        foreach (GatewayCommandSubmission::where(fn ($q) => $q->whereIn('state', ['pending', 'retrying'])->orWhere(fn ($q) => $q->where('state', 'submitting')->where('leased_until', '<', now())))
            ->where(fn ($q) => $q->whereNull('next_attempt_at')->orWhere('next_attempt_at', '<=', now()))->orderBy('created_at')->limit($limit)->get() as $submission) {
            SubmitGatewayCommand::dispatch($submission->id)->onConnection('gateway')->onQueue('device-commands');
        }
        // A failed binding is retried explicitly from master; configuration failures must not spin forever.
        foreach (GatewayDeviceBinding::whereNull('synced_at')->whereNull('last_error')->limit($limit)->get() as $binding) {
            SyncGatewayBinding::dispatch($binding->id)->onConnection('gateway')->onQueue('device-commands');
        }
        // Round-robin ownership scanning bounds work without starving later companies.
        $central = DB::connection(config('tenancy.database.central_connection'));
        $cursorRow = $central->table('gateway_runtime_states')->where('id', 'recovery-cursor')->first();
        $cursor = json_decode($cursorRow?->details ?? '{}', true)['last_organisation_id'] ?? '';
        $organisations = Organisation::where('id', '>', $cursor)->orderBy('id')->limit(10)->get();
        if ($organisations->isEmpty()) {
            $organisations = Organisation::orderBy('id')->limit(10)->get();
        }
        $tenantLimit = min($limit, 20);
        foreach ($organisations as $organisation) {
            try {
                tenancy()->initialize($organisation);
                if (! Schema::hasTable('gateway_domain_effects')) {
                    continue;
                }
                if ($organisation->device_integration === \App\Services\Devices\DeviceIntegration::GATEWAY) {
                    foreach (\App\Models\Device::whereNull('options->profile_policy_version')
                        ->whereDoesntHave('commands', fn ($q) => $q->where('command_type', 'info')->where(fn ($q) => $q->whereIn('status', ['pending', 'sent', 'outcome_unknown'])->orWhere('created_at', '>=', now()->subDay())))
                        ->orderBy('id')->limit($tenantLimit)->get() as $device) {
                        app(\App\Services\Devices\Gateway\DeviceDiscovery::class)->request($device);
                    }
                }
                foreach (DB::table('gateway_command_outbox')->whereNull('relayed_at')->orderBy('created_at')->limit($tenantLimit)->get() as $row) {
                    $commands->relay($organisation->id, $row->id);
                }
                DB::table('gateway_domain_effects')->where('state', 'processing')->where('attempts', '>=', 3)->where('leased_until', '<', now())->update(['state' => 'parked', 'error_code' => 'lease_exhausted']);
                $effectCounts = DB::table('gateway_domain_effects')->select('state')->selectRaw('count(*) as total')->groupBy('state')->pluck('total', 'state')->all();
                GatewayMonitor::beat('tenant-effects', 'effects:'.$organisation->id, $effectCounts, $organisation->id);
                foreach (DB::table('gateway_domain_effects')->where(fn ($q) => $q->where('state', 'pending')->orWhere(fn ($q) => $q->where('state', 'processing')->where('leased_until', '<', now())))->orderBy('id')->limit($tenantLimit)->get() as $effect) {
                    ApplyDomainEffect::dispatch($organisation->id, $effect->id)->onConnection('gateway')->onQueue($effect->type === 'notification' ? 'exports-notifications' : 'attendance-live');
                }
            } catch (\Throwable $exception) {
                report($exception);
            } finally {
                tenancy()->end();
            }
        }
        if ($organisations->isNotEmpty()) {
            GatewayMonitor::beat('recovery-cursor', 'recovery-cursor', ['last_organisation_id' => $organisations->last()->id]);
        }
        $this->info('Gateway recovery scan complete.');

        return self::SUCCESS;
    }
}
