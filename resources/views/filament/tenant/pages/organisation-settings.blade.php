<x-filament-panels::page>
    @if (\App\Services\Devices\DeviceIntegration::usesEbio())
        <x-filament::section :heading="__('organisation-settings.ebio')" :description="__('organisation-settings.ebio_help')">
            @include('filament.components.ebio-webhook-setup', ['organisation' => tenant()])
        </x-filament::section>
    @endif
    <form wire:submit="save" class="space-y-6">
        {{ $this->form }}
        <x-filament::button type="submit">{{ __('organisation-settings.save_timezone') }}</x-filament::button>
    </form>
</x-filament-panels::page>
