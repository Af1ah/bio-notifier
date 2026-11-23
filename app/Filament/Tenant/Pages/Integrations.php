<?php

namespace App\Filament\Tenant\Pages;

use App\Models\FrappeSetting;
use App\Services\Integrations\FrappeCheckins;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Forms\Concerns\InteractsWithForms;
use Filament\Forms\Contracts\HasForms;
use Filament\Notifications\Notification;
use Filament\Schemas\Components\Section;
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

    public function form(Schema $schema): Schema
    {
        return $schema->components([
            Section::make(__('organisation-settings.frappe'))
                ->description(__('organisation-settings.frappe_help'))
                ->schema([
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
                ]),
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

    public function deliverySummary(): array
    {
        abort_unless(static::canAccess(), 403);

        $deliveries = DB::table('frappe_checkin_deliveries');
        $totals = (clone $deliveries)->selectRaw(
            'COUNT(sent_at) AS sent, COUNT(retry_at) AS pending, COUNT(*) - COUNT(sent_at) - COUNT(retry_at) AS failed'
        )->first();

        return [
            'sent' => $totals->sent,
            'pending' => $totals->pending,
            'failed' => $totals->failed,
            'cooldown' => FrappeSetting::find(1)?->cooldown_until,
            'recent' => $deliveries->whereNotNull('last_attempt_at')->orderByDesc('last_attempt_at')
                ->orderByDesc('attendance_log_id')->limit(15)->get(),
        ];
    }

    public function deliveryError(string $error): string
    {
        return match ($error) {
            'destination', 'connection', 'unexpected_response' => __('organisation-settings.'.$error),
            default => __('organisation-settings.http_error', ['status' => substr($error, 5)]),
        };
    }

    private function notify(bool $ok, string $message): void
    {
        $notification = Notification::make()->title(__('organisation-settings.'.($ok ? $message : 'frappe_busy')));
        ($ok ? $notification->success() : $notification->warning())->send();
    }
}
