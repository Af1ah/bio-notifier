<x-filament-panels::page x-data x-on:print-report.window="setTimeout(() => window.print(), 500)">
    <x-filament::tabs>
        <x-filament::tabs.item wire:click="$set('activeTab', 'daily')" :active="$activeTab === 'daily'">Daily Report</x-filament::tabs.item>
        <x-filament::tabs.item wire:click="$set('activeTab', 'weekly')" :active="$activeTab === 'weekly'">Weekly Report</x-filament::tabs.item>
        <x-filament::tabs.item wire:click="$set('activeTab', 'monthly')" :active="$activeTab === 'monthly'">Monthly Report</x-filament::tabs.item>
        <x-filament::tabs.item wire:click="$set('activeTab', 'templates')" :active="$activeTab === 'templates'">Saved Templates</x-filament::tabs.item>
    </x-filament::tabs>

    <div wire:key="table-{{ $activeTab }}">
        {{ $this->table }}
    </div>
</x-filament-panels::page>
