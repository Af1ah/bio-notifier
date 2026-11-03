<x-filament-widgets::widget>
    @php
        $stats = $this->summary();
        $tenant = ['tenant' => tenant('shortname')];
        $shortcuts = [
            ['label' => 'Calendar', 'url' => \App\Filament\Employee\Pages\Calendar::getUrl($tenant, panel: 'employee')],
            ['label' => 'Attendance', 'url' => \App\Filament\Employee\Pages\Attendance::getUrl($tenant, panel: 'employee')],
            ['label' => 'Leave requests', 'url' => \App\Filament\Employee\Pages\LeaveRequests::getUrl($tenant, panel: 'employee')],
            ['label' => 'Salary slips', 'url' => \App\Filament\Employee\Pages\SalarySlips::getUrl($tenant, panel: 'employee')],
        ];
    @endphp
    <x-filament::section>
        <div style="display: flex; flex-wrap: wrap; justify-content: space-between; gap: 1rem; align-items: baseline;">
            <div><div class="text-sm text-gray-500">{{ $stats[0]->getLabel() }}</div><div style="font-size: 1.25rem; font-weight: 600;">{{ $stats[0]->getValue() }}</div><div class="text-sm">{{ $stats[0]->getDescription() }}</div></div>
            <div class="text-sm text-gray-500">{{ $stats[3]->getDescription() }}</div>
        </div>
        <div style="display: grid; grid-template-columns: repeat(3, minmax(0, 1fr)); gap: .75rem; margin-top: 1.25rem;">
            @foreach (array_slice($stats, 3, 3) as $stat)
                <a href="{{ $stat->getUrl() }}" style="min-width: 0; text-decoration: none;" class="text-sm">
                    <span class="text-gray-500">{{ $stat->getLabel() }}</span><br><strong style="font-size: 1.125rem;">{{ $stat->getValue() }}</strong>
                </a>
            @endforeach
        </div>
        <div style="display: flex; flex-wrap: wrap; gap: 1rem; margin-top: 1rem;" class="text-sm">
            <a href="{{ $stats[1]->getUrl() }}" class="underline">Attendance reviews: {{ $stats[1]->getValue() }}</a>
            <a href="{{ $stats[2]->getUrl() }}" class="underline">Leave requests pending: {{ $stats[2]->getValue() }}</a>
        </div>
        <div x-data="{ shortcuts: @js($shortcuts) }" style="margin-top: 1.25rem;">
            <div style="font-weight: 600; margin-bottom: .5rem;">Shortcuts</div>
            <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(min(100%, 9rem), 1fr)); gap: .5rem;">
                <template x-for="shortcut in shortcuts" x-bind:key="shortcut.url">
                    <x-filament::button tag="a" color="gray" x-bind:href="shortcut.url"><span x-text="shortcut.label"></span></x-filament::button>
                </template>
            </div>
        </div>
    </x-filament::section>
</x-filament-widgets::widget>
