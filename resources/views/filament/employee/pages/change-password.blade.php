<x-filament-panels::page>
    <x-filament::section class="max-w-lg">
        <form wire:submit="save" class="space-y-6">
            {{ $this->form }}
            <div style="display: flex; justify-content: flex-end; margin-top: 1rem;">
                <x-filament::button type="submit" wire:loading.attr="disabled" wire:target="save">Save password</x-filament::button>
            </div>
        </form>
    </x-filament::section>
</x-filament-panels::page>
