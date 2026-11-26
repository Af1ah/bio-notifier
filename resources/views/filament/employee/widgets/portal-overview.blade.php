@use(App\Filament\Employee\Pages\Attendance)
@use(App\Filament\Employee\Pages\Calendar)
@use(App\Filament\Employee\Pages\SalarySlips)
@use(Filament\Support\Icons\Heroicon)

<x-filament-widgets::widget>
    @php
        $stats = $this->summary();
        $activity = $this->recentActivity();
        $shortcuts = [
            [Attendance::class, 'attendance', Heroicon::OutlinedClock],
            [Calendar::class, 'calendar', Heroicon::OutlinedCalendarDays],
            [SalarySlips::class, 'payslips', Heroicon::OutlinedBanknotes],
        ];
    @endphp
    <div class="portal-stack portal-ess-width">
        <h2 class="portal-kicker">{{ __('portal-ui.shortcuts') }}</h2>
        <div class="portal-shortcuts">
            <x-portal-ui.button :icon="Heroicon::OutlinedCamera" variant="tile" x-on:click="$dispatch('ess-open-manual-punch')">
                {{ __('portal-ui.manual_punch') }}
            </x-portal-ui.button>
            @foreach ($shortcuts as [$page, $label, $icon])
                <x-portal-ui.button :href="$page::getUrl(panel: 'employee')" :icon="$icon" variant="tile" wire:navigate>
                    {{ __('portal-ui.'.$label) }}
                </x-portal-ui.button>
            @endforeach
        </div>

        <x-portal-ui.card class="portal-stack">
            <div class="portal-row">
                <h2 class="portal-section-title">{{ $stats[3]->getDescription() }}</h2>
                <a class="portal-inline-link" href="{{ Calendar::getUrl(panel: 'employee') }}" wire:navigate>{{ __('portal-ui.calendar') }}</a>
            </div>
            <dl class="portal-metrics portal-metrics--tiles">
                @foreach (array_slice($stats, 3, 3) as $stat)
                    <x-portal-ui.metric :label="$stat->getLabel()" :value="$stat->getValue()" />
                @endforeach
            </dl>
            <div class="portal-actions">
                @foreach (array_slice($stats, 1, 2) as $stat)
                    <x-portal-ui.button :href="$stat->getUrl()" wire:navigate>
                        {{ $stat->getLabel() }}{{ (int) $stat->getValue() ? ' · '.$stat->getValue() : '' }}
                    </x-portal-ui.button>
                @endforeach
            </div>
        </x-portal-ui.card>
        <section class="portal-stack" aria-labelledby="portal-recent-activity">
            <h2 id="portal-recent-activity" class="portal-section-title">{{ __('portal-ui.recent_activity') }}</h2>
            @forelse ($activity as $punch)
                <x-portal-ui.punch-row :title="$punch['title']" :at="$punch['at']" :detail="$punch['source']"
                    :state="$punch['state']" :icon="$punch['state'] === null ? Heroicon::OutlinedCpuChip : Heroicon::OutlinedCamera" />
            @empty
                <p class="portal-kicker">{{ __('portal-ui.no_punches') }}</p>
            @endforelse
        </section>
    </div>
</x-filament-widgets::widget>
