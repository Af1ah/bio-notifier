<x-filament-widgets::widget>
    <x-filament::section heading="Check in or out">
            <div style="width: 100%; max-width: 32rem; margin-inline: auto;" x-data="{
                location: $wire.$entangle('data.location_id'), busy: false, starting: false, error: '', request: null,
                cameraOpen: false, stream: null, kind: null, disposed: false,
                async start(kind) {
                    if (this.busy || this.starting) return;
                    this.error = ''; this.kind = kind;
                    if (!navigator.mediaDevices?.getUserMedia || !window.isSecureContext) { this.error = 'Camera access needs HTTPS and a supported browser.'; return; }
                    this.cameraOpen = true; this.starting = true;
                    try {
                        const stream = await navigator.mediaDevices.getUserMedia({ video: { facingMode: 'user', width: { ideal: 960 }, height: { ideal: 720 } }, audio: false });
                        if (!this.cameraOpen || this.disposed) { stream.getTracks().forEach(track => track.stop()); return; }
                        this.stream = stream; this.$refs.video.srcObject = stream; await this.$refs.video.play();
                    } catch (error) { this.error = 'Allow camera access and try again.'; this.stopCamera(); }
                    finally { this.starting = false; }
                },
                stopCamera() {
                    this.stream?.getTracks().forEach(track => track.stop()); this.stream = null; this.cameraOpen = false;
                },
                async punch() {
                    if (this.busy || this.starting || !this.stream || !this.$refs.video.videoWidth) return;
                    this.error = '';
                    if (!navigator.geolocation) { this.error = 'Location is unavailable in this browser.'; return; }
                    this.busy = true;
                    if (!this.request || this.request.kind !== this.kind) this.request = { kind: this.kind, id: crypto.randomUUID() };
                    try {
                        const position = await new Promise((resolve, reject) => navigator.geolocation.getCurrentPosition(resolve, reject, { enableHighAccuracy: true, maximumAge: 0, timeout: 20000 }));
                        if (this.disposed || !this.stream) return;
                        const video = this.$refs.video, canvas = this.$refs.canvas;
                        const scale = Math.min(1, 960 / Math.max(video.videoWidth, video.videoHeight));
                        canvas.width = Math.round(video.videoWidth * scale); canvas.height = Math.round(video.videoHeight * scale);
                        canvas.getContext('2d').drawImage(video, 0, 0, canvas.width, canvas.height);
                        const gps = { latitude: position.coords.latitude, longitude: position.coords.longitude, accuracy: position.coords.accuracy, captured_at: position.timestamp };
                        if ($wire.geofenceEnabled && this.location !== null && this.location !== '') gps.location_id = Number(this.location);
                        const saved = await $wire.punch({ kind: this.kind, request_id: this.request.id, ...gps, photo: canvas.toDataURL('image/jpeg', 0.8) });
                        canvas.width = 0; canvas.height = 0;
                        if (saved) { this.request = null; this.stopCamera(); }
                    } catch (error) { this.error = error.code === 1 ? 'Allow location access to request your punch.' : 'Could not submit your punch. Check your connection and GPS, then retry.'; }
                    finally { this.busy = false; }
                },
                destroy() { this.disposed = true; this.stopCamera(); }
            }" x-on:pagehide.window="stopCamera()" x-on:visibilitychange.document="if (document.hidden) stopCamera()">
                @if ($this->geofenceEnabled)
                    <div style="margin-bottom: 1rem;">
                        {{ $this->form }}
                        <p class="text-sm text-gray-500" style="margin-top: .5rem;">If selected, you must be within its allowed radius.</p>
                    </div>
                @endif

                <div x-show="!cameraOpen" style="display: grid; grid-template-columns: repeat(auto-fit, minmax(min(100%, 9rem), 1fr)); gap: .75rem;">
                    <x-filament::button type="button" x-on:click="start('in')" x-bind:disabled="busy || starting">Check in</x-filament::button>
                    <x-filament::button type="button" color="gray" x-on:click="start('out')" x-bind:disabled="busy || starting">Check out</x-filament::button>
                </div>

                <p class="text-sm text-gray-500" style="margin-top: .75rem; line-height: 1.5;">A photo and location are required. HR or Admin will review your punch.</p>

                <div x-cloak x-show="cameraOpen" style="margin-top: 1.25rem;">
                    <p style="font-weight: 600; margin-bottom: .75rem;" x-text="kind === 'in' ? 'Check in photo' : 'Check out photo'"></p>
                    <video x-ref="video" autoplay playsinline muted style="display: block; width: 100%; max-height: 24rem; aspect-ratio: 4 / 3; object-fit: contain; background: #111827; border-radius: .75rem;" aria-label="Live camera preview"></video>
                    <canvas x-ref="canvas" hidden></canvas>
                    <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(min(100%, 9rem), 1fr)); gap: .75rem; margin-top: 1rem;">
                        <x-filament::button type="button" x-on:click="punch()" x-bind:disabled="busy || starting">Take photo and request</x-filament::button>
                        <x-filament::button type="button" color="gray" x-on:click="stopCamera()" x-bind:disabled="busy">Cancel</x-filament::button>
                    </div>
                </div>
                <p x-cloak x-show="busy || starting" class="text-sm text-gray-500" style="margin-top: .75rem;" role="status">Please wait…</p>
                <p x-cloak x-show="error" x-text="error" class="text-sm text-danger-600" style="margin-top: .75rem;" role="alert"></p>

                @if ($last = $this->lastPunch())
                    <div style="margin-top: 1.25rem;">
                        <p class="text-sm text-gray-500" style="margin-bottom: .25rem;">Last request</p>
                        <p class="text-sm" style="line-height: 1.5; overflow-wrap: anywhere;">{{ $last->kind === 'in' ? 'Check in' : 'Check out' }} · {{ $last->punched_at->format('d M, h:i A') }} · {{ ucfirst($last->state) }}@if (filled($last->evidence['location_name'] ?? null)) · {{ $last->evidence['location_name'] }}@endif</p>
                    </div>
                @endif
            </div>
    </x-filament::section>
</x-filament-widgets::widget>
