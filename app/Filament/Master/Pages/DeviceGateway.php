<?php

namespace App\Filament\Master\Pages;

use App\Jobs\Gateway\ApplyDomainEffect;
use App\Jobs\Gateway\ApplyGatewayReceipt;
use App\Jobs\Gateway\SubmitGatewayCommand;
use App\Models\GatewayCommandSubmission;
use App\Models\GatewayConnection;
use App\Models\GatewayDeviceBinding;
use App\Models\GatewayIngressReceipt;
use App\Models\Organisation;
use App\Services\Devices\Gateway\GatewayClient;
use Filament\Pages\Page;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Livewire\Attributes\Locked;

class DeviceGateway extends Page
{
    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-server-stack';

    protected static ?string $navigationLabel = 'System status';

    protected static ?string $title = 'System status';

    protected static string|\UnitEnum|null $navigationGroup = 'Platform';

    protected string $view = 'filament.master.pages.device-gateway';

    #[Locked]
    public array $summary = [];

    #[Locked]
    public bool $detailsLoaded = false;

    public string $receiptState = 'parked';

    #[Locked]
    public string $activeReceiptState = 'parked';

    public int $receiptPage = 1;

    public ?string $inspectionOrganisation = null;

    #[Locked]
    public array $calculationEffects = [];

    #[Locked]
    public array $health = [];

    #[Locked]
    public array $gatewayCommands = [];

    #[Locked]
    public array $gatewayEvents = [];

    #[Locked]
    public ?int $connectionId = null;

    public static function canAccess(): bool
    {
        return auth('admin')->user()?->role === 'master';
    }

    private function authorizeMaster(): void
    {
        abort_unless(self::canAccess(), 403);
    }

    private function audit(string $action, string $subject, array $metadata = []): void
    {
        DB::connection(config('tenancy.database.central_connection'))->table('gateway_audit_events')->insert([
            'admin_id' => auth('admin')->id(), 'action' => $action, 'subject' => $subject,
            'metadata' => json_encode($metadata), 'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    public function mount(): void
    {
        $this->authorizeMaster();
        $this->connectionId = GatewayConnection::where('enabled', true)->value('id');
        $this->refreshStatus();
    }

    public function refreshStatus(): void
    {
        $this->authorizeMaster();
        $this->summary = [
            'receipts' => GatewayIngressReceipt::select('state')->selectRaw('count(*) as total')->groupBy('state')->pluck('total', 'state')->all(),
            'commands' => GatewayCommandSubmission::select('state')->selectRaw('count(*) as total')->groupBy('state')->pluck('total', 'state')->all(),
            'queues' => DB::connection(config('queue.connections.database.connection'))->table('jobs')->select('queue')->selectRaw('count(*) as total, min(created_at) as oldest')->groupBy('queue')->get()->map(fn ($row) => (array) $row)->all(),
            'workers' => DB::connection(config('tenancy.database.central_connection'))->table('gateway_runtime_states')->whereNull('organisation_id')->orderBy('role')->limit(30)->get(['role', 'heartbeat_at'])->map(fn ($row) => (array) $row)->all(),
            'refreshed_at' => now()->toIso8601String(),
        ];
    }

    public function chooseConnection(int $id): void
    {
        $this->authorizeMaster();
        abort_unless(GatewayConnection::whereKey($id)->where('enabled', true)->exists(), 422);
        $this->connectionId = $id;
        $this->health = $this->gatewayCommands = $this->gatewayEvents = [];
        $this->detailsLoaded = false;
    }

    public function checkHealth(): void
    {
        $this->authorizeMaster();
        try {
            $connection = GatewayConnection::findOrFail($this->connectionId);
            $this->health = app(GatewayClient::class)->call($connection, 'GET', '/v1/health');
            $connection->update(['last_healthy_at' => now()]);
        } catch (\Throwable $exception) {
            $this->health = ['status' => 'unavailable'];
        }
    }

    public function loadDetails(): void
    {
        $this->authorizeMaster();
        $this->validate(['receiptState' => 'required|in:parked,retrying,pending,processing,applied']);
        $this->detailsLoaded = true;
        $this->activeReceiptState = $this->receiptState;
        $this->receiptPage = 1;
        $this->gatewayCommands = $this->gatewayEvents = [];
        if ($this->connectionId) {
            try {
                $connection = GatewayConnection::findOrFail($this->connectionId);
                $this->gatewayCommands = app(GatewayClient::class)->call($connection, 'GET', '/v1/commands');
                $this->gatewayEvents = app(GatewayClient::class)->call($connection, 'GET', '/v1/events');
            } catch (\Throwable $exception) {
                $this->health = ['status' => 'unavailable'];
            }
        }
    }

    public function changeReceiptPage(int $page): void
    {
        $this->authorizeMaster();
        abort_unless($this->detailsLoaded && $page >= 1 && $page <= 10000, 422);
        $this->receiptPage = $page;
    }

    public function replayReceipt(string $id): void
    {
        $this->authorizeMaster();
        $receipt = GatewayIngressReceipt::findOrFail($id);
        abort_unless(in_array($receipt->state, ['parked', 'retrying'], true), 422);
        $receipt->update(['state' => 'pending', 'attempts' => 0, 'lease_token' => null, 'leased_until' => null, 'next_attempt_at' => now(), 'error_code' => null]);
        $this->audit('receipt_replay', $id);
        ApplyGatewayReceipt::dispatch($id)->onConnection('gateway')->onQueue('device-ingest');
    }

    public function resolveCommand(string $id): void
    {
        $this->authorizeMaster();
        $submission = GatewayCommandSubmission::findOrFail($id);
        $binding = GatewayDeviceBinding::findOrFail($submission->binding_id);
        $result = app(GatewayClient::class)->call(GatewayConnection::findOrFail($binding->gateway_connection_id), 'POST', '/v1/commands/'.$id.'/resolve');
        abort_unless(($result['accepted'] ?? false) === true, 422);
        $this->audit('unknown_command_resolved', $id);
        $this->loadDetails();
    }

    public function retrySubmission(string $id): void
    {
        $this->authorizeMaster();
        $submission = GatewayCommandSubmission::findOrFail($id);
        abort_unless($submission->state === 'parked' && $submission->expires_at->isFuture(), 422);
        $submission->update(['state' => 'pending', 'attempts' => 0, 'next_attempt_at' => now(), 'error_code' => null, 'lease_token' => null, 'leased_until' => null]);
        $this->audit('command_submission_retry', $id);
        SubmitGatewayCommand::dispatch($id)->onConnection('gateway')->onQueue('device-commands');
    }

    public function replayGatewayEvent(string $id): void
    {
        $this->authorizeMaster();
        abort_unless(Str::isUuid($id), 422);
        $result = app(GatewayClient::class)->call(GatewayConnection::findOrFail($this->connectionId), 'POST', '/v1/events/'.$id.'/replay');
        abort_unless(($result['accepted'] ?? false) === true, 422);
        $this->audit('gateway_event_replay', $id);
    }

    public function inspectCalculations(): void
    {
        $this->authorizeMaster();
        $this->calculationEffects = [];
        try {
            tenancy()->initialize(Organisation::findOrFail($this->inspectionOrganisation));
            $this->calculationEffects = DB::table('gateway_domain_effects')->where('type', 'calculation')->where('state', 'parked')
                ->orderBy('id')->limit(50)->get(['id', 'receipt_id', 'attempts', 'error_code'])->map(fn ($row) => (array) $row)->all();
        } finally {
            tenancy()->end();
        }
    }

    public function replayCalculation(string $organisationId, int $effectId): void
    {
        $this->authorizeMaster();
        try {
            tenancy()->initialize(Organisation::findOrFail($organisationId));
            $updated = DB::table('gateway_domain_effects')->where('id', $effectId)->where('type', 'calculation')->where('state', 'parked')
                ->update(['state' => 'pending', 'attempts' => 0, 'lease_token' => null, 'leased_until' => null, 'error_code' => null]);
            abort_unless($updated, 422);
            ApplyDomainEffect::dispatch($organisationId, $effectId)->onConnection('gateway')->onQueue('attendance-live');
        } finally {
            tenancy()->end();
        }
        $this->audit('calculation_replay', $organisationId.':'.$effectId);
        $this->inspectCalculations();
    }

    protected function getViewData(): array
    {
        $this->authorizeMaster();
        abort_unless($this->receiptPage >= 1 && $this->receiptPage <= 10000, 422);

        return [
            'connections' => GatewayConnection::where('enabled', true)->orderBy('id')->get(['id', 'name']),
            'inspectionCompanies' => $this->detailsLoaded ? Organisation::orderBy('name')->limit(100)->pluck('name', 'id') : collect(),
            'receipts' => $this->detailsLoaded ? GatewayIngressReceipt::where('gateway_connection_id', $this->connectionId)->where('state', $this->activeReceiptState)->orderByDesc('created_at')->orderByDesc('id')->paginate(25, ['id', 'kind', 'state', 'attempts', 'error_code', 'created_at'], 'receiptPage', $this->receiptPage) : null,
            'submissions' => $this->detailsLoaded ? GatewayCommandSubmission::whereIn('binding_id', GatewayDeviceBinding::where('gateway_connection_id', $this->connectionId)->select('id'))->where('state', 'parked')->orderByDesc('updated_at')->limit(25)->get(['id', 'error_code', 'expires_at']) : collect(),
        ];
    }
}
