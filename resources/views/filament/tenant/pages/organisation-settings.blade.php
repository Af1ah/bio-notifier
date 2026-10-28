<x-filament-panels::page>
    @if (\App\Services\Devices\DeviceIntegration::usesEbio() && (int) auth('web')->user()?->privilege === 14)
        <div style="max-width:640px">
            <x-filament::section heading="eBio setup" description="Copy these settings into eBio → Utilities → Webhook (Post Logs).">
                @include('filament.components.ebio-webhook-setup', ['organisation' => tenant()])
            </x-filament::section>
        </div>
    @endif
    <form wire:submit="save" class="space-y-6" style="max-width:640px">
        {{ $this->form }}
        <x-filament::button type="submit">Save timezone</x-filament::button>
    </form>
</x-filament-panels::page>
