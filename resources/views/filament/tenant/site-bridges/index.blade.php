<x-filament-panels::page>
@include('filament.tenant.site-bridges.styles')
<div class="bridge-hub" wire:poll.5s>
    <section class="bridge-card"><h2>Connect your sites</h2><p class="bridge-muted bridge-help">One ESP connects each site's Matrix devices to Biomatrix. Review connection requests here before granting access.</p><div class="bridge-steps"><div class="bridge-step"><strong>1. Add a site</strong><p class="bridge-muted">Give the bridge a name you can recognise.</p></div><div class="bridge-step"><strong>2. Request a connection</strong><p class="bridge-muted">Generate a code and enter it on the ESP dashboard.</p></div><div class="bridge-step"><strong>3. Approve the ESP</strong><p class="bridge-muted">Check its identity, approve it, then add your Matrix devices.</p></div></div></section>
    @php($bridges = $this->bridges())
    <div class="bridge-grid">@forelse($bridges as $bridge) @include('filament.tenant.site-bridges.bridge', ['bridge'=>$bridge,'overview'=>true]) @empty <section class="bridge-card bridge-empty"><h2>No sites yet</h2><p class="bridge-muted bridge-help">Select Add site to connect your first ESP.</p></section> @endforelse</div>
    {{ $bridges->links() }}
</div>
</x-filament-panels::page>
