<x-filament-panels::page>
@include('filament.tenant.site-bridges.styles')
<div class="bridge-hub">
    <div wire:poll.5s>@include('filament.tenant.site-bridges.bridge', ['bridge'=>$this->bridgeDetails()])</div>
    <section class="bridge-card"><h2>Site details</h2><p class="bridge-muted bridge-help">Use a clear name for the location this ESP serves.</p><div class="bridge-help">{{ $this->content }}</div></section>
</div>
</x-filament-panels::page>
