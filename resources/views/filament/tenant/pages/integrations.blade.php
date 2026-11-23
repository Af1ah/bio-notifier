<x-filament-panels::page>
    @php
        $summary = $this->deliverySummary();
        $paused = $summary['cooldown']?->isFuture();
        $status = ! $summary['hostname'] ? 'not_connected' : ($paused ? 'paused' : ($summary['enabled'] ? 'enabled' : 'disabled'));
    @endphp

    <x-filament::section :heading="__('organisation-settings.frappe')" icon="heroicon-o-puzzle-piece">
        <div class="flex flex-col gap-5 lg:flex-row lg:items-start lg:justify-between">
            <div class="min-w-0 space-y-3">
                <x-filament::badge :color="$status === 'enabled' ? 'success' : ($status === 'paused' ? 'warning' : 'gray')">
                    {{ __('organisation-settings.frappe_connection_'.$status) }}
                </x-filament::badge>
                @if ($summary['hostname'])
                    <p class="text-sm break-all text-gray-500 dark:text-gray-400">{{ $summary['hostname'] }}</p>
                    <div class="flex flex-wrap items-center gap-2" role="status" aria-live="polite">
                        <x-filament::badge :color="! $summary['health'] ? 'gray' : ($summary['health']['status'] === 'healthy' ? 'success' : 'danger')">
                            {{ __('organisation-settings.frappe_health_'.(! $summary['health'] ? 'unchecked' : ($summary['health']['status'] === 'healthy' ? 'healthy' : 'failed'))) }}
                        </x-filament::badge>
                        @if ($summary['health'])
                            <span class="text-sm text-gray-500 dark:text-gray-400">{{ __('organisation-settings.frappe_health_checked', ['time' => \Carbon\Carbon::parse($summary['health']['checked_at'])->setTimezone(\App\Services\Support\TenantTimezone::get())->format('H:i T')]) }}</span>
                        @endif
                    </div>
                    @if (($summary['health']['status'] ?? null) === 'healthy')
                        <p class="text-sm text-gray-500 dark:text-gray-400">{{ __('organisation-settings.frappe_health_scope') }}</p>
                    @elseif ($summary['health'])
                        <p class="text-sm text-gray-500 dark:text-gray-400">{{ __('organisation-settings.frappe_health_'.$summary['health']['status']) }}</p>
                    @endif
                @endif
                @if ($paused)
                    <p class="text-sm text-gray-500 dark:text-gray-400">{{ __('organisation-settings.frappe_paused_until', ['time' => $summary['cooldown']->setTimezone(\App\Services\Support\TenantTimezone::get())->format('Y-m-d H:i T')]) }}</p>
                @endif
            </div>
            <div class="flex shrink-0 flex-wrap gap-3">
                @if ($summary['hostname'])
                    <x-filament::button color="gray" wire:click="checkHealth" wire:loading.attr="disabled" wire:target="checkHealth" icon="heroicon-o-signal">
                        {{ __('organisation-settings.frappe_check_health') }}
                    </x-filament::button>
                @endif
                <x-filament::button wire:click="connect" icon="heroicon-o-link">
                    {{ __('organisation-settings.'.($summary['hostname'] ? 'frappe_edit_connection' : 'frappe_connect')) }}
                </x-filament::button>
            </div>
        </div>
    </x-filament::section>

    <x-filament::modal id="frappe-connection" width="2xl" wire:submit.prevent="save">
        <x-slot name="heading">{{ __('organisation-settings.frappe') }}</x-slot>
        <x-slot name="description">{{ __('organisation-settings.frappe_help') }}</x-slot>
        {{ $this->form }}
        <x-slot name="footer">
            <div class="flex flex-wrap justify-end gap-3">
                <x-filament::button color="gray" x-on:click="$dispatch('close-modal', { id: 'frappe-connection' })">
                    {{ __('organisation-settings.cancel') }}
                </x-filament::button>
                <x-filament::button type="submit" wire:loading.attr="disabled" wire:target="save">
                    {{ __('organisation-settings.frappe_save') }}
                </x-filament::button>
            </div>
        </x-slot>
    </x-filament::modal>

    <x-filament::section :heading="__('organisation-settings.frappe_status')">
        <x-slot name="afterHeader">
            <div class="flex flex-wrap gap-2">
                {{ $this->syncHistoryAction }}
                <x-filament::button tag="a" :href="\App\Filament\Tenant\Pages\Integrations::getUrl()" wire:navigate color="gray" size="sm" icon="heroicon-o-arrow-path">
                    {{ __('organisation-settings.frappe_refresh') }}
                </x-filament::button>
                @if ($summary['failed'])
                    <x-filament::button wire:click="retryFailed()" color="gray" size="sm">
                        {{ __('organisation-settings.frappe_retry') }}
                    </x-filament::button>
                @endif
            </div>
        </x-slot>
        <div class="space-y-5">
            <dl class="grid grid-cols-3 gap-4 border-b border-gray-200 pb-5 dark:border-white/10">
                @foreach (['sent', 'pending', 'failed'] as $metric)
                    <div class="min-w-0 space-y-1">
                        <dt class="text-sm text-gray-500 dark:text-gray-400">{{ __('organisation-settings.frappe_metric_'.$metric) }}</dt>
                        <dd class="text-xl font-semibold tabular-nums text-gray-950 dark:text-white">{{ number_format($summary[$metric]) }}</dd>
                    </div>
                @endforeach
            </dl>
            <div class="rounded-lg bg-gray-50 p-4 dark:bg-white/5">
                <p class="text-sm font-medium">{{ __('organisation-settings.frappe_last_synced') }}</p>
                @if ($last = $summary['last_synced'])
                    <p class="mt-2 text-sm">{{ $last->employee_name ?: __('organisation-settings.frappe_employee_pin', ['pin' => $last->pin]) }} · {{ \Carbon\Carbon::parse($last->punched_at)->format('d M Y, H:i') }}</p>
                    <p class="mt-1 text-sm text-gray-500 dark:text-gray-400">{{ __('organisation-settings.frappe_synced_at', ['time' => \Carbon\Carbon::parse($last->sent_at)->setTimezone(\App\Services\Support\TenantTimezone::get())->format('d M Y, H:i T')]) }}</p>
                @else
                    <p class="mt-2 text-sm text-gray-500 dark:text-gray-400">{{ __('organisation-settings.frappe_never_synced') }}</p>
                @endif
            </div>
            <div class="divide-y divide-gray-200 dark:divide-white/10">
                @forelse ($summary['recent'] as $delivery)
                    <div class="flex flex-wrap items-center justify-between gap-3 py-3" wire:key="checkin-{{ $delivery->attendance_log_id }}">
                        <div class="min-w-0 space-y-1">
                            <p class="text-sm font-medium">{{ __('organisation-settings.punch_number', ['id' => $delivery->attendance_log_id]) }}</p>
                            <p class="text-sm">{{ $delivery->employee_name ?: __('organisation-settings.frappe_employee_pin', ['pin' => $delivery->pin]) }} · {{ \Carbon\Carbon::parse($delivery->punched_at)->format('d M Y, H:i') }}</p>
                            <p class="text-sm text-gray-500 dark:text-gray-400">{{ __('organisation-settings.delivery_attempts', ['count' => $delivery->attempts]) }}</p>
                            @if ($delivery->error && $delivery->retry_at)
                                <p class="text-sm text-gray-500 dark:text-gray-400">{{ __('organisation-settings.frappe_retry_at', ['time' => \Carbon\Carbon::parse($delivery->retry_at)->setTimezone(\App\Services\Support\TenantTimezone::get())->format('d M, H:i T')]) }}</p>
                            @endif
                            @if ($delivery->error)
                                <p class="text-sm text-gray-500 dark:text-gray-400">{{ $this->deliveryError($delivery->error) }}</p>
                            @endif
                        </div>
                        <div class="flex flex-wrap items-center gap-3">
                            <x-filament::badge :color="$delivery->sent_at ? 'success' : ($delivery->retry_at ? 'warning' : 'danger')">
                                {{ __('organisation-settings.frappe_result_'.($delivery->sent_at ? ($delivery->outcome ?? 'sent') : ($delivery->retry_at ? 'retrying' : 'failed'))) }}
                            </x-filament::badge>
                            @if ($delivery->error)
                                <x-filament::button size="sm" color="gray" wire:click="retryFailed({{ $delivery->attendance_log_id }})">
                                    {{ __('organisation-settings.resend') }}
                                </x-filament::button>
                            @endif
                        </div>
                    </div>
                @empty
                    <div class="flex flex-col items-center gap-3 px-2 py-8 text-center">
                        <x-filament::icon icon="heroicon-o-inbox-arrow-down" class="h-8 w-8 text-gray-400 dark:text-gray-500" />
                        <p class="text-sm font-medium text-gray-950 dark:text-white">{{ __('organisation-settings.frappe_no_deliveries') }}</p>
                        <p class="max-w-sm text-sm text-gray-500 dark:text-gray-400">{{ __('organisation-settings.frappe_delivery_hint') }}</p>
                    </div>
                @endforelse
            </div>
        </div>
    </x-filament::section>
</x-filament-panels::page>
