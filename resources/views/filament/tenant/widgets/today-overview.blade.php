<x-filament-widgets::widget class="portal-stack">
    <div class="portal-admin-grid" wire:poll.30s>
        @foreach ($this->summary() as $stat)
            <x-portal-ui.card>
                <dl><x-portal-ui.metric :label="$stat->getLabel()" :value="$stat->getValue()" /></dl>
                @if ($stat->getUrl())
                    <x-portal-ui.button :href="$stat->getUrl()" :icon="$stat->getDescriptionIcon()" wire:navigate>
                        {{ $stat->getDescription() }}
                    </x-portal-ui.button>
                @endif
            </x-portal-ui.card>
        @endforeach
    </div>
</x-filament-widgets::widget>
