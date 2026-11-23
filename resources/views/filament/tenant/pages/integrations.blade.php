<x-filament-panels::page>
    <form wire:submit="save" class="space-y-6">
        {{ $this->form }}
        <x-filament::button type="submit">
            {{ __('organisation-settings.frappe_save') }}
        </x-filament::button>
    </form>

    <div>
        @php($summary = $this->deliverySummary())
        <x-filament::section :heading="__('organisation-settings.frappe_status')">
            <div class="space-y-3">
                <p>{{ __('organisation-settings.frappe_counts', $summary) }}</p>
                @if ($summary['cooldown']?->isFuture())
                    <p>{{ __('organisation-settings.frappe_paused_until', ['time' => $summary['cooldown']->setTimezone(\App\Services\Support\TenantTimezone::get())->format('Y-m-d H:i T')]) }}</p>
                @endif
                @foreach ($summary['recent'] as $delivery)
                    <div class="flex flex-wrap items-center gap-3" wire:key="checkin-{{ $delivery->attendance_log_id }}">
                        <span>{{ __('organisation-settings.punch_number', ['id' => $delivery->attendance_log_id]) }}</span>
                        <span>{{ __('organisation-settings.delivery_attempts', ['count' => $delivery->attempts]) }}</span>
                        <x-filament::badge :color="$delivery->sent_at ? 'success' : ($delivery->retry_at ? 'warning' : 'danger')">
                            {{ __('organisation-settings.frappe_result_'.($delivery->sent_at ? ($delivery->outcome ?? 'sent') : ($delivery->retry_at ? 'retrying' : 'failed'))) }}
                        </x-filament::badge>
                        @if ($delivery->error)
                            <span>{{ $this->deliveryError($delivery->error) }}</span>
                            <x-filament::button size="sm" color="gray" wire:click="retryFailed({{ $delivery->attendance_log_id }})">
                                {{ __('organisation-settings.resend') }}
                            </x-filament::button>
                        @endif
                    </div>
                @endforeach
                <x-filament::button tag="a" :href="\App\Filament\Tenant\Pages\Integrations::getUrl()" wire:navigate color="gray">
                    {{ __('organisation-settings.frappe_refresh') }}
                </x-filament::button>
                @if ($summary['failed'])
                    <x-filament::button wire:click="retryFailed()" color="gray">
                        {{ __('organisation-settings.frappe_retry') }}
                    </x-filament::button>
                @endif
            </div>
        </x-filament::section>
    </div>
</x-filament-panels::page>
