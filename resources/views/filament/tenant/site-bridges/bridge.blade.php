@php
    $requests = $bridge->pairingRequests;
    $pending = $requests->filter(fn($request) => $request->isPending());
    $history = $requests->filter(fn($request) => !$request->isPending());
    $state = $bridge->revoked_at ? 'Revoked' : ($bridge->public_key ? ($bridge->isOnline() ? 'Online' : 'Offline') : ($pending->isNotEmpty() ? 'Approval needed' : 'Not connected'));
    $code = $bridge->activePairingCode();
@endphp
<article class="bridge-card bridge-stack" wire:key="bridge-{{ $bridge->id }}">
    <div class="bridge-row"><h2>{{ $bridge->name }}</h2><span class="bridge-badge"><span class="bridge-dot {{ $bridge->isOnline() ? 'online' : ($pending->isNotEmpty() ? 'pending' : '') }}"></span>{{ $state }}</span></div>
    @if($bridge->revoked_at)
        <p class="bridge-muted">This ESP no longer has access. Add a new site bridge to connect replacement hardware.</p>
    @elseif($bridge->public_key)
        <div class="bridge-meta"><span>ESP identity: <strong>{{ $bridge->hardware_id }}</strong></span><span class="bridge-muted">Last connected: {{ $bridge->last_seen_at ? $bridge->last_seen_at->diffForHumans() : 'Waiting for its first connection' }}</span></div>
        <p class="bridge-muted">Select this site bridge when adding Matrix devices. Each device needs a unique local IP.</p>
        <div class="bridge-actions"><x-filament::button tag="a" :href="\App\Filament\Tenant\Resources\DeviceResource::getUrl()" color="gray">Manage devices</x-filament::button><x-filament::button color="danger" wire:click="revokeBridge('{{ $bridge->id }}')" wire:confirm="Revoke this ESP's access? Active connections will close." wire:loading.attr="disabled">Revoke access</x-filament::button></div>
    @elseif($pending->isEmpty())
        <p class="bridge-muted">Generate a code here, then enter it on the ESP's Remote bridge page. Its request will appear here automatically.</p>
        @if($code && $bridge->pairing_expires_at?->isFuture())
            <div x-data="{
                copied: false, copyFailed: false,
                async copyCode() {
                    let success = false;
                    try { if (window.isSecureContext &amp;&amp; navigator.clipboard) { await navigator.clipboard.writeText(this.$refs.code.value); success = true; } } catch (error) {}
                    if (!success) { this.$refs.code.focus(); this.$refs.code.select(); try { success = document.execCommand('copy'); } catch (error) {} }
                    this.copied = success; this.copyFailed = !success;
                    if (success) setTimeout(() =&gt; this.copied = false, 3000);
                }
            }" class="bridge-stack">
                <label class="bridge-muted" for="pairing-code-{{ $bridge->id }}">Pairing code</label>
                <input id="pairing-code-{{ $bridge->id }}" x-ref="code" class="bridge-code" value="{{ $code }}" readonly x-on:focus="$el.select()" autocomplete="off" spellcheck="false">
                <div class="bridge-row"><span class="bridge-muted">Code expires {{ $bridge->pairing_expires_at->diffForHumans() }}.</span><x-filament::button type="button" color="gray" x-on:click="copyCode()"><span x-text="copied ? 'Copied' : 'Copy code'">Copy code</span></x-filament::button></div>
                <p class="bridge-muted" x-show="copyFailed" x-cloak role="status">Clipboard access was blocked. The code is selected; press Ctrl+C or copy it manually.</p>
            </div>
            <p class="bridge-muted">Refreshing this page or opening another site page keeps this code. A new code is generated only after it expires.</p>
            <p class="bridge-muted">On the ESP, use server <strong>{{ rtrim(config('bridge.app_public_url'), '/') }}</strong> and this code, then select Request connection.</p>
        @endif
        @if(!$code)
            <div class="bridge-actions"><x-filament::button wire:click="generateCode('{{ $bridge->id }}')" wire:loading.attr="disabled">Generate pairing code</x-filament::button></div>
        @endif
    @endif
    @foreach($pending as $request)
        <section class="bridge-request bridge-stack" wire:key="request-{{ $request->id }}">
            <div class="bridge-row"><strong>ESP connection request</strong><span class="bridge-badge">Pending approval</span></div>
            <div class="bridge-meta"><span>ESP identity: <strong>{{ $request->hardware_id }}</strong></span><span class="bridge-muted">Requested {{ $request->created_at->diffForHumans() }} · Expires {{ $request->expires_at->diffForHumans() }}</span><span class="bridge-muted">Key fingerprint: <code>{{ $request->fingerprint() }}</code></span></div>
            <p class="bridge-muted">Confirm this is your ESP before approving. Approval allows it to forward this site's Matrix traffic.</p>
            @if(!$bridge->revoked_at && !$bridge->public_key)
                <div class="bridge-actions"><x-filament::button wire:click="approveRequest('{{ $request->id }}')" wire:confirm="Approve this ESP to connect to this site?" wire:loading.attr="disabled">Approve ESP</x-filament::button><x-filament::button color="gray" wire:click="rejectRequest('{{ $request->id }}')" wire:confirm="Reject this connection request?" wire:loading.attr="disabled">Reject request</x-filament::button></div>
            @endif
        </section>
    @endforeach
    @if($history->isNotEmpty())
        <details class="bridge-history"><summary>Recent requests ({{ $history->count() }})</summary><div class="bridge-stack bridge-requests">@foreach($history as $request)<div class="bridge-row bridge-meta"><span>{{ $request->hardware_id }} · {{ ucfirst($request->displayStatus()) }}</span><span class="bridge-muted">{{ $request->created_at->format('d M Y, H:i') }}</span></div>@endforeach</div></details>
    @endif
    @if($overview ?? false)<div class="bridge-actions"><x-filament::button tag="a" :href="\App\Filament\Tenant\Resources\SiteBridgeResource::getUrl('edit', ['record'=>$bridge])" color="gray">Manage site</x-filament::button></div>@endif
</article>
