<x-filament-widgets::widget>
    <x-filament::section heading="Check in or out">
        @if (! $this->hasLocations())
            <p class="text-sm text-gray-500">HR or Admin needs to add a work location before you can punch.</p>
        @else
            <div x-data="{
                location: $wire.$entangle('data.location_id'), busy: false, starting: false, error: '', request: null,
                cameraOpen: false, stream: null, kind: null, disposed: false,
                async start(kind) {
                    if (this.busy || this.starting) return;
                    this.error = ''; this.kind = kind;
                    if (!navigator.mediaDevices?.getUserMedia || !window.isSecureContext) { this.error = 'Camera and location access need HTTPS and a supported browser.'; return; }
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
                        const saved = await $wire.punch({ kind: this.kind, request_id: this.request.id, location_id: Number(this.location),
                            latitude: position.coords.latitude, longitude: position.coords.longitude, accuracy: position.coords.accuracy,
                            captured_at: position.timestamp, photo: canvas.toDataURL('image/jpeg', 0.8) });
                        canvas.width = 0; canvas.height = 0;
                        if (saved) { this.request = null; this.stopCamera(); }
                    } catch (error) { this.error = error.code === 1 ? 'Allow location access to request your punch.' : 'Could not submit your punch. Check your connection and GPS, then retry.'; }
                    finally { this.busy = false; }
                },
                destroy() { this.disposed = true; this.stopCamera(); }
            }" x-on:pagehide.window="stopCamera()" x-on:visibilitychange.document="if (document.hidden) stopCamera()">
                <div class="flex flex-wrap items-end gap-3">
                    <div style="width: min(100%, 22rem)">{{ $this->form }}</div>
                    <x-filament::button type="button" x-on:click="start('in')" x-bind:disabled="busy || starting || cameraOpen">Check in</x-filament::button>
                    <x-filament::button type="button" color="gray" x-on:click="start('out')" x-bind:disabled="busy || starting || cameraOpen">Check out</x-filament::button>
                </div>
                <p class="mt-2 text-sm text-gray-500">A photo and location are required. HR or Admin will review your punch.</p>
                <div x-cloak x-show="cameraOpen" class="mt-3" style="max-width: 24rem">
                    <video x-ref="video" autoplay playsinline muted style="width: 100%; border-radius: .75rem;" aria-label="Live camera preview"></video>
                    <canvas x-ref="canvas" hidden></canvas>
                    <div class="mt-3 flex flex-wrap gap-3">
                        <x-filament::button type="button" x-on:click="punch()" x-bind:disabled="busy || starting">Take photo and request</x-filament::button>
                        <x-filament::button type="button" color="gray" x-on:click="stopCamera()" x-bind:disabled="busy">Cancel</x-filament::button>
                    </div>
                </div>
                <p x-cloak x-show="busy || starting" class="mt-2 text-sm text-gray-500" role="status">Please wait…</p>
                <p x-cloak x-show="error" x-text="error" class="mt-2 text-sm text-danger-600" role="alert"></p>
            </div>
            @if ($last = $this->lastPunch())
                <p class="mt-3 text-sm text-gray-500">Last request: {{ $last->kind === 'in' ? 'Check in' : 'Check out' }} · {{ $last->punched_at->format('d M, h:i A') }} · {{ ucfirst($last->state) }} · {{ $last->evidence['location_name'] }}</p>
            @endif
        @endif
    </x-filament::section>
</x-filament-widgets::widget>
