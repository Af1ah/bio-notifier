<x-filament-panels::page>
    <form wire:submit="save" class="space-y-6" style="max-width:640px">
        {{ $this->form }}
        <x-filament::button type="submit">Save timezone</x-filament::button>
    </form>
</x-filament-panels::page>
