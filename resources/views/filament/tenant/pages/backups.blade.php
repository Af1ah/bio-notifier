<x-filament-panels::page>
    <x-filament::section
        :heading="__('organisation-settings.database_backup')"
        :description="__('organisation-settings.backup_help')"
    >
        @error('backup')
            <p role="alert">{{ $message }}</p>
        @enderror
        <form method="POST" action="{{ route('filament.tenant.organisation-backups.download') }}">
            @csrf
            <x-filament::button type="submit" :icon="\Filament\Support\Icons\Heroicon::OutlinedArrowDownTray">
                {{ __('organisation-settings.download_sql') }}
            </x-filament::button>
        </form>
    </x-filament::section>
    <x-filament::section :heading="__('organisation-settings.drive_schedule')">
        <div class="space-y-4">
            @if (request()->query('drive') === 'connection_failed')
                <p role="alert">{{ __('organisation-settings.drive_connection_failed') }}</p>
            @endif
            @error('drive')
                <p role="alert">{{ $message }}</p>
            @enderror
            @if ($connected)
                <p>{{ __('organisation-settings.drive_connected', ['name' => $settings->drive_name ?: 'Google Drive']) }}</p>
            @endif
            @if ($configured)
                <form method="POST" action="{{ route('filament.tenant.organisation-backups.google.connect') }}">
                    @csrf
                    <x-filament::button type="submit">
                        {{ __($connected ? 'organisation-settings.drive_reconnect' : 'organisation-settings.drive_connect') }}
                    </x-filament::button>
                    @if ($connected)
                        <x-filament::button type="button" color="gray" wire:click="disconnect" wire:confirm="{{ __('organisation-settings.drive_disconnect_confirm') }}">
                            {{ __('organisation-settings.drive_disconnect') }}
                        </x-filament::button>
                    @endif
                </form>
            @else
                <p>{{ __('organisation-settings.no_drive') }}</p>
            @endif
        </div>
    </x-filament::section>

    @if ($configured && $connected)
        <form wire:submit="save" class="space-y-4">
            {{ $this->form }}
            <x-filament::button type="submit">{{ __('organisation-settings.backup_save') }}</x-filament::button>
            <x-filament::button type="button" wire:click="queueBackup" color="gray">
                {{ __('organisation-settings.backup_now') }}
            </x-filament::button>
        </form>
    @endif

    <x-filament::section :heading="__('organisation-settings.backup_history')">
        <div wire:poll.30s.visible class="space-y-3">
            @if ($settings?->next_run_at)
                <p>{{ __('organisation-settings.next_backup', ['time' => $settings->next_run_at->setTimezone($settings->timezone)->format('Y-m-d H:i T')]) }}</p>
            @endif
            @forelse ($runs as $run)
                <div wire:key="backup-{{ $run->id }}" class="flex flex-wrap items-center gap-3">
                    <span>{{ $run->created_at->setTimezone(\App\Services\Support\TenantTimezone::get())->format('Y-m-d H:i T') }}</span>
                    <x-filament::badge :color="match ($run->status) { 'verified' => 'success', 'failed' => 'danger', 'retrying' => 'warning', default => 'gray' }">
                        {{ __('organisation-settings.backup_status_'.$run->status) }}
                    </x-filament::badge>
                    @if ($run->error)
                        <span>{{ __('organisation-settings.backup_error_'.$run->error) }}</span>
                    @endif
                    @if ($run->status === 'verified' && $run->drive_file_id)
                        <x-filament::button tag="a" size="sm" color="gray" :href="'https://drive.google.com/open?id='.rawurlencode($run->drive_file_id)" target="_blank" rel="noopener noreferrer">
                            {{ __('organisation-settings.open_in_drive') }}
                        </x-filament::button>
                    @endif
                    @if ($configured && $connected && $run->status === 'failed' && $run->connection_id === $settings?->connection_id)
                        <x-filament::button size="sm" color="gray" wire:click="retryBackup('{{ $run->id }}')">
                            {{ __('organisation-settings.backup_retry') }}
                        </x-filament::button>
                    @endif
                </div>
            @empty
                <p>{{ __('organisation-settings.no_backups') }}</p>
            @endforelse
        </div>
    </x-filament::section>
</x-filament-panels::page>
