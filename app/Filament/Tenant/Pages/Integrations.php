<?php

namespace App\Filament\Tenant\Pages;

use App\Models\FrappeSetting;
use App\Services\Integrations\FrappeCheckins;
use App\Services\Support\TenantTimezone;
use Filament\Actions\Action;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Forms\Concerns\InteractsWithForms;
use Filament\Forms\Contracts\HasForms;
use Filament\Notifications\Notification;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class Integrations extends SettingsPage implements HasForms
{
    use InteractsWithForms;

    protected static ?string $slug = 'organisation-settings/integrations';

    protected static string|\BackedEnum|null $navigationIcon = Heroicon::OutlinedPuzzlePiece;

    protected static bool $shouldRegisterNavigation = false;

    protected string $view = 'filament.tenant.pages.integrations';

    public ?array $data = [];

    public static function getNavigationLabel(): string
    {
        return __('organisation-settings.integrations');
    }

    public function mount(): void
    {
        abort_unless(static::canAccess(), 403);

        $settings = FrappeSetting::find(1);
        $this->form->fill([
            'hostname' => $settings?->hostname,
            'enabled' => $settings?->enabled ?? false,
        ]);
    }

    public function connect(): void
    {
        $this->resetValidation();
        $this->mount();
        $this->dispatch('open-modal', id: 'frappe-connection');
    }

    public function form(Schema $schema): Schema
    {
        return $schema->components([
            TextInput::make('hostname')->label(__('organisation-settings.frappe_host'))
                ->helperText(__('organisation-settings.frappe_host_help'))
                ->required()->maxLength(253)->regex(FrappeCheckins::HOSTNAME_PATTERN),
            TextInput::make('api_key')->label(__('organisation-settings.frappe_key'))
                ->password()->autocomplete('new-password')->maxLength(128)->regex('/\A[a-zA-Z0-9]+\z/')
                ->helperText(__('organisation-settings.frappe_keep')),
            TextInput::make('api_secret')->label(__('organisation-settings.frappe_secret'))
                ->password()->autocomplete('new-password')->maxLength(128)->regex('/\A[a-zA-Z0-9]+\z/')
                ->helperText(__('organisation-settings.frappe_keep')),
            Toggle::make('enabled')->label(__('organisation-settings.frappe_enabled'))
                ->helperText(__('organisation-settings.frappe_enabled_help')),
        ])->statePath('data');
    }

    public function save(): void
    {
        abort_unless(static::canAccess(), 403);

        try {
            $state = $this->form->getState();
            $saved = Cache::lock(FrappeCheckins::LOCK, 30)->get(fn () => DB::connection('tenant')->transaction(function () use ($state) {
                $settings = FrappeSetting::lockForUpdate()->findOrNew(1);
                $hostname = strtolower($state['hostname']);
                if ($settings->exists && $settings->hostname !== $hostname && DB::table('frappe_checkin_deliveries')->whereNull('sent_at')->exists()) {
                    throw ValidationException::withMessages(['data.hostname' => __('organisation-settings.frappe_pending_host')]);
                }

                $changingDestination = ! $settings->exists || $settings->hostname !== $hostname;
                foreach (['api_key', 'api_secret'] as $credential) {
                    if (filled($state[$credential] ?? null)) {
                        $settings->{$credential} = $state[$credential];
                    } elseif ($changingDestination) {
                        throw ValidationException::withMessages(['data.'.$credential => __('organisation-settings.frappe_credential_required')]);
                    }
                }

                $settings->fill(['id' => 1, 'hostname' => $hostname, 'enabled' => $state['enabled']]);
                if ($settings->isDirty(['hostname', 'enabled', 'api_key', 'api_secret'])) {
                    $settings->cooldown_until = null;
                }
                $settings->save();

                return true;
            }));
        } finally {
            $this->data['api_key'] = null;
            $this->data['api_secret'] = null;
        }

        $this->notify($saved, 'frappe_saved');
        if ($saved) {
            $this->dispatch('close-modal', id: 'frappe-connection');
        }
    }

    public function retryFailed(?int $attendanceLogId = null): void
    {
        abort_unless(static::canAccess(), 403);

        $this->data['api_key'] = null;
        $this->data['api_secret'] = null;

        $queued = Cache::lock(FrappeCheckins::LOCK, 30)->get(fn () => DB::transaction(function () use ($attendanceLogId) {
            $query = DB::table('frappe_checkin_deliveries')->whereNull('sent_at')->whereNotNull('error');
            if ($attendanceLogId !== null) {
                $query->where('attendance_log_id', $attendanceLogId);
            } else {
                $query->whereNull('retry_at');
            }

            if ($query->update(['retry_at' => now(), 'attempts' => 0, 'error' => null])) {
                FrappeSetting::whereKey(1)->update(['cooldown_until' => null]);
            }

            return true;
        }));

        $this->notify($queued, 'frappe_retried');
    }

    public function syncHistoryAction(): Action
    {
        return Action::make('syncHistory')->label(__('organisation-settings.frappe_history'))
            ->icon('heroicon-o-clock')->color('gray')->modalWidth('md')
            ->modalDescription(__('organisation-settings.frappe_history_help'))
            ->modalSubmitActionLabel(__('organisation-settings.frappe_history_queue'))
            ->visible(fn () => FrappeSetting::whereKey(1)->where('enabled', true)->exists())
            ->schema([
                DatePicker::make('from')->label(__('organisation-settings.frappe_history_from'))->default('2020-01-01')->required(),
                DatePicker::make('to')->label(__('organisation-settings.frappe_history_to'))
                    ->default(fn () => today(TenantTimezone::get())->toDateString())->required()->afterOrEqual('from'),
            ])
            ->action(function (array $data): void {
                abort_unless(static::canAccess(), 403);
                $count = Cache::lock(FrappeCheckins::LOCK, 30)->get(fn () => app(FrappeCheckins::class)->queueHistory($data['from'], $data['to']));
                if ($count === null) {
                    $this->notify(false, 'frappe_busy');

                    return;
                }
                Notification::make()->title(__('organisation-settings.frappe_history_queued', ['count' => $count]))
                    ->body(__('organisation-settings.'.($count === 5000 ? 'frappe_history_more' : 'frappe_history_delivery')))->success()->send();
            });
    }

    public function checkHealth(): void
    {
        abort_unless(static::canAccess(), 403);
        $settings = FrappeSetting::find(1);
        if (! $settings) {
            Notification::make()->title(__('organisation-settings.frappe_health_not_configured'))->warning()->send();

            return;
        }
        if (! Cache::add('frappe-health-throttle:'.tenant('id'), true, 15)) {
            Notification::make()->title(__('organisation-settings.frappe_health_wait'))->warning()->send();

            return;
        }

        $frappe = app(FrappeCheckins::class);
        $status = $frappe->health($settings, $frappe->resolvePublicAddress($settings->hostname));
        Cache::put($this->healthKey($settings), ['status' => $status, 'checked_at' => now()->toIso8601String()], 300);
        $notification = Notification::make()->title(__('organisation-settings.frappe_health_'.$status));
        ($status === 'healthy' ? $notification->success() : $notification->danger())->send();
    }

    private function healthKey(FrappeSetting $settings): string
    {
        return 'frappe-health:'.tenant('id').':'.hash('sha256', json_encode([
            $settings->hostname, $settings->getRawOriginal('api_key'), $settings->getRawOriginal('api_secret'),
        ]));
    }

    public function deliverySummary(): array
    {
        abort_unless(static::canAccess(), 403);

        $deliveries = DB::table('frappe_checkin_deliveries');
        $totals = (clone $deliveries)->selectRaw(
            'COUNT(sent_at) AS sent, COUNT(retry_at) AS pending, COUNT(*) - COUNT(sent_at) - COUNT(retry_at) AS failed'
        )->first();
        $settings = FrappeSetting::find(1);
        $details = DB::table('frappe_checkin_deliveries as deliveries')
            ->join('attendance_logs as logs', 'logs.id', '=', 'deliveries.attendance_log_id')
            ->leftJoin('users', 'users.pin', '=', 'logs.pin')
            ->select('deliveries.*', 'logs.pin', 'logs.punched_at', 'users.name as employee_name');

        return [
            'sent' => $totals->sent,
            'pending' => $totals->pending,
            'failed' => $totals->failed,
            'hostname' => $settings?->hostname,
            'health' => $settings ? Cache::get($this->healthKey($settings)) : null,
            'enabled' => $settings?->enabled ?? false,
            'cooldown' => $settings?->cooldown_until,
            'last_synced' => (clone $details)->whereNotNull('sent_at')->orderByDesc('sent_at')->orderByDesc('attendance_log_id')->first(),
            'recent' => $details->orderByRaw('COALESCE(deliveries.last_attempt_at, deliveries.retry_at) DESC')->orderByDesc('attendance_log_id')->limit(15)->get(),
        ];
    }

    public function deliveryError(string $error): string
    {
        return match ($error) {
            'destination', 'connection', 'unexpected_response' => __('organisation-settings.'.$error),
            'employee_not_found', 'log_type_required' => __('organisation-settings.frappe_error_'.$error),
            'http_401' => __('organisation-settings.frappe_health_authentication'),
            'http_403' => __('organisation-settings.frappe_error_permission'),
            'http_404' => __('organisation-settings.frappe_error_endpoint'),
            'http_429' => __('organisation-settings.frappe_health_rate_limited'),
            default => __('organisation-settings.http_error', ['status' => substr($error, 5)]),
        };
    }

    private function notify(bool $ok, string $message): void
    {
        $notification = Notification::make()->title(__('organisation-settings.'.($ok ? $message : 'frappe_busy')));
        ($ok ? $notification->success() : $notification->warning())->send();
    }
}
