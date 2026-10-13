<?php

namespace App\Filament\Master\Pages;

use App\Jobs\Gateway\ApplyDomainEffect;
use App\Jobs\Gateway\ApplyGatewayReceipt;
use App\Jobs\Gateway\SubmitGatewayCommand;
use App\Jobs\Gateway\SyncGatewayBinding;
use App\Models\GatewayCommandSubmission;
use App\Models\GatewayConnection;
use App\Models\GatewayDeviceBinding;
use App\Models\GatewayIngressReceipt;
use App\Models\Organisation;
use App\Services\Devices\Gateway\GatewayClient;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Locked;

class DeviceGateway extends Page
{
    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-server-stack';

    protected static ?string $navigationLabel = 'Attendance service';

    protected static ?string $title = 'Attendance service';

    protected static string|\UnitEnum|null $navigationGroup = 'Device operations';

    protected string $view = 'filament.master.pages.device-gateway';

    public array $connectionData = [];

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
        $connection = GatewayConnection::first();
        $this->connectionId = $connection?->id;
        $this->connectionForm->fill(['name' => $connection?->name ?? 'Primary gateway', 'management_url' => $connection?->management_url,
            'device_server_url' => $connection?->device_server_url,
            'enabled' => $connection?->enabled ?? false]);
    }

    public function connectionForm(Schema $schema): Schema
    {
        return $schema->statePath('connectionData')->components([
            Section::make('Server connection')->description('Configure the shared attendance service. Companies add and manage their devices from their own Devices page.')->schema([
                TextInput::make('name')->required()->maxLength(255),
                TextInput::make('device_server_url')->label('Device server address')->url()->maxLength(255)->helperText('The address companies enter in their device’s server settings.'),
                TextInput::make('management_url')->label('Service address')->required()->url()->in(config('device_gateway.allowed_origins')),
                TextInput::make('management_token')->label('Management token')->password()->minLength(32)->maxLength(255)->helperText('Leave blank to retain the saved token.'),
                TextInput::make('events_token')->label('Event delivery token')->password()->minLength(32)->maxLength(255)->helperText('Leave blank to retain the saved token.'),
                Toggle::make('enabled')->label('Enable connection'),
            ])->columns(2),
        ]);
    }

    public function saveConnection(): void
    {
        $this->authorizeMaster();
        $data = $this->connectionForm->getState();
        $connection = $this->connectionId ? GatewayConnection::findOrFail($this->connectionId) : new GatewayConnection;
        foreach (['management_token', 'events_token'] as $key) {
            if (blank($data[$key] ?? null)) {
                unset($data[$key]);
            }
            if (! isset($data[$key]) && ! $connection->{$key}) {
                throw ValidationException::withMessages(['connectionData.'.$key => 'Set a service token with at least 32 characters.']);
            }
        }
        $connection->fill($data)->save();
        $this->connectionId = $connection->id;
        $this->connectionData['management_token'] = '';
        $this->connectionData['events_token'] = '';
        $this->audit('connection_saved', (string) $connection->id, ['enabled' => $connection->enabled]);
        Notification::make()->title('Connection saved')->success()->send();
    }

    public function checkHealth(): void
    {
        $this->authorizeMaster();
        try {
            $connection = GatewayConnection::findOrFail($this->connectionId);
            $this->health = app(GatewayClient::class)->call($connection, 'GET', '/v1/health');
            $this->gatewayCommands = app(GatewayClient::class)->call($connection, 'GET', '/v1/commands');
            $this->gatewayEvents = app(GatewayClient::class)->call($connection, 'GET', '/v1/events');
            $connection->update(['last_healthy_at' => now()]);
        } catch (\Throwable $exception) {
            $this->health = ['status' => 'unavailable'];
        }
    }

    public function retryBinding(int $id): void
    {
        $this->authorizeMaster();
        $binding = GatewayDeviceBinding::findOrFail($id);
        $binding->update(['last_error' => null, 'synced_at' => null]);
        $this->audit('binding_retry', (string) $id);
        SyncGatewayBinding::dispatch($id)->onConnection('gateway')->onQueue('device-commands');
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
        $this->checkHealth();
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

        return [
            'bindingCount' => GatewayDeviceBinding::count(),
            'inspectionCompanies' => Organisation::orderBy('name')->limit(100)->pluck('name', 'id'),
            'bindings' => GatewayDeviceBinding::orderByDesc('updated_at')->limit(100)->get(),
            'receipts' => GatewayIngressReceipt::orderByDesc('created_at')->limit(50)->get(['id', 'kind', 'state', 'attempts', 'error_code', 'created_at']),
            'receiptCounts' => GatewayIngressReceipt::select('state')->selectRaw('count(*) as total')->groupBy('state')->pluck('total', 'state'),
            'submissions' => GatewayCommandSubmission::where('state', 'parked')->orderByDesc('updated_at')->limit(50)->get(),
            'commandCounts' => GatewayCommandSubmission::select('state')->selectRaw('count(*) as total')->groupBy('state')->pluck('total', 'state'),
            'queues' => DB::connection(config('queue.connections.database.connection'))->table('jobs')->select('queue')->selectRaw('count(*) as total')->selectRaw('min(created_at) as oldest')->groupBy('queue')->get(),
            'runtimeStates' => DB::connection(config('tenancy.database.central_connection'))->table('gateway_runtime_states')->orderBy('role')->limit(100)->get(),
        ];
    }
}
