<?php

namespace App\Filament\Tenant\Pages;

use App\Models\OrganisationBackupRun;
use App\Models\OrganisationBackupSetting;
use App\Services\Backups\BackupSchedule;
use App\Services\Backups\GoogleDrive;
use App\Services\Support\TenantTimezone;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TimePicker;
use Filament\Forms\Concerns\InteractsWithForms;
use Filament\Forms\Contracts\HasForms;
use Filament\Notifications\Notification;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class Backups extends SettingsPage implements HasForms
{
    use InteractsWithForms;

    protected static ?string $slug = 'organisation-settings/backups';

    protected static string|\BackedEnum|null $navigationIcon = Heroicon::OutlinedCircleStack;

    protected static bool $shouldRegisterNavigation = false;

    protected string $view = 'filament.tenant.pages.backups';

    public ?array $data = [];

    public static function getNavigationLabel(): string
    {
        return __('organisation-settings.backups');
    }

    public function mount(): void
    {
        abort_unless(static::canAccess(), 403);

        $settings = OrganisationBackupSetting::find(tenant('id'));
        $this->form->fill([
            'frequency' => $settings?->frequency,
            'backup_time' => $settings?->backup_time ?? '02:00',
        ]);
    }

    public function form(Schema $schema): Schema
    {
        return $schema->components([
            Section::make(__('organisation-settings.backup_schedule'))
                ->description(__('organisation-settings.drive_schedule_help', ['timezone' => TenantTimezone::get()]))
                ->schema([
                    Select::make('frequency')->label(__('organisation-settings.frequency'))
                        ->options([
                            'daily' => __('organisation-settings.daily'),
                            'weekly' => __('organisation-settings.weekly'),
                            'monthly' => __('organisation-settings.monthly'),
                        ])->placeholder(__('organisation-settings.manual_only'))
                        ->rule(Rule::in(['daily', 'weekly', 'monthly'])),
                    TimePicker::make('backup_time')->label(__('organisation-settings.backup_time'))
                        ->seconds(false)->format('H:i')->required()->rule('date_format:H:i'),
                ])->columns(2),
        ])->statePath('data');
    }

    public function save(): void
    {
        abort_unless(static::canAccess(), 403);
        $state = $this->form->getState();
        $id = (string) tenant('id');

        $this->locked(function () use ($state, $id) {
            $timezone = TenantTimezone::get();
            $settings = $this->connectedSetting($id);
            $settings->fill([
                'frequency' => $state['frequency'] ?? null,
                'backup_time' => $state['backup_time'],
                'timezone' => $timezone,
            ]);
            if ($settings->isDirty(['frequency', 'backup_time', 'timezone'])) {
                $settings->next_run_at = BackupSchedule::next($settings->frequency, $settings->backup_time, $timezone);
            }
            $settings->save();
        }, 'backup_saved');
    }

    public function queueBackup(): void
    {
        abort_unless(static::canAccess(), 403);
        $id = (string) tenant('id');

        $this->locked(function () use ($id) {
            $settings = $this->connectedSetting($id);

            if (! OrganisationBackupRun::where('organisation_id', $id)->whereNotNull('available_at')->exists()) {
                BackupSchedule::queue($id, $settings->connection_id);
            }
        }, 'backup_queued');
    }

    public function retryBackup(string $runId): void
    {
        abort_unless(static::canAccess(), 403);
        abort_unless(Str::isUuid($runId), 404);
        $id = (string) tenant('id');

        $this->locked(function () use ($id, $runId) {
            $run = OrganisationBackupRun::where('organisation_id', $id)->findOrFail($runId);
            $settings = $this->connectedSetting($id);
            if ($run->status !== 'failed' || $run->connection_id !== $settings->connection_id) {
                throw ValidationException::withMessages(['drive' => __('organisation-settings.backup_retry_unavailable')]);
            }

            $run->update(['status' => 'queued', 'attempts' => 0, 'error' => null, 'available_at' => now()]);
        }, 'backup_queued');
    }

    public function disconnect(): void
    {
        abort_unless(static::canAccess(), 403);
        $id = (string) tenant('id');

        $this->locked(function () use ($id) {
            OrganisationBackupSetting::whereKey($id)->update(['refresh_token' => null, 'next_run_at' => null]);
            OrganisationBackupRun::where('organisation_id', $id)->whereNotNull('available_at')->update([
                'status' => 'failed', 'error' => 'connection_changed', 'available_at' => null,
            ]);
        }, 'drive_disconnected');
    }

    private function connectedSetting(string $id): OrganisationBackupSetting
    {
        $settings = OrganisationBackupSetting::find($id);
        if (! $settings?->refresh_token || ! GoogleDrive::configured()) {
            throw ValidationException::withMessages(['drive' => __('organisation-settings.select_drive')]);
        }

        return $settings;
    }

    protected function getViewData(): array
    {
        $id = tenant('id');
        $settings = OrganisationBackupSetting::find($id);

        return [
            'settings' => $settings,
            'configured' => GoogleDrive::configured(),
            'connected' => $settings?->getRawOriginal('refresh_token') !== null,
            'runs' => OrganisationBackupRun::where('organisation_id', $id)->latest()->orderByDesc('id')->limit(20)->get(),
        ];
    }

    private function locked(callable $operation, string $success): void
    {
        $saved = BackupSchedule::lock((string) tenant('id'))->get(function () use ($operation) {
            $operation();

            return true;
        });
        $message = Notification::make()->title(__('organisation-settings.'.($saved ? $success : 'backup_busy')));
        ($saved ? $message->success() : $message->warning())->send();
    }
}
