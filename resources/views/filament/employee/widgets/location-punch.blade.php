<x-filament-widgets::widget>
    <link rel="stylesheet" href="{{ asset('css/ess-punch.css') }}">
    <x-filament::section :heading="__('employee-punch.heading')">
        <div class="ess-punch" x-data="{
            labels: @js(__('employee-punch')),
            location: $wire.$entangle('data.location_id'),
            busy: false, starting: false, error: '', request: null,
            nativeCamera: false, ready: false, stream: null, kind: null, receipt: null,
            openCamera(kind) {
                if (this.busy || this.starting) return;
                this.kind = kind;
                this.error = '';
                this.nativeCamera = window.matchMedia('(pointer: coarse)').matches;
                this.$refs.cameraDialog.showModal();
                if (!this.nativeCamera) this.startPreview();
            },
            async startPreview() {
                if (this.starting) return;
                this.error = '';
                if (!navigator.mediaDevices?.getUserMedia || !window.isSecureContext) {
                    this.error = this.labels.secureCamera;
                    return;
                }
                this.starting = true;
                try {
                    const stream = await navigator.mediaDevices.getUserMedia({ video: { facingMode: 'user', width: { ideal: 960 }, height: { ideal: 720 } }, audio: false });
                    if (!this.$refs.cameraDialog?.open || document.hidden) {
                        stream.getTracks().forEach(track => track.stop());
                        return;
                    }
                    this.stream = stream;
                    this.$refs.video.srcObject = stream;
                    await this.$refs.video.play();
                    this.ready = this.stream === stream;
                } catch {
                    this.stopPreview();
                    this.error = this.labels.cameraPermission;
                } finally {
                    this.starting = false;
                }
            },
            stopPreview() {
                this.stream?.getTracks().forEach(track => track.stop());
                this.stream = null;
                this.ready = false;
                if (this.$refs.video) this.$refs.video.srcObject = null;
            },
            async capture() {
                if (this.busy || this.starting) return;
                this.error = '';
                if (this.nativeCamera) {
                    this.$refs.cameraInput.value = '';
                    this.$refs.cameraInput.click();
                    return;
                }
                if (this.ready) await this.submitPhoto(this.$refs.video);
            },
            async phonePhoto(event) {
                const file = event.target.files[0];
                if (!file || this.busy || !this.$refs.cameraDialog.open) return;
                this.starting = true;
                const url = URL.createObjectURL(file);
                try {
                    const image = new Image();
                    image.src = url;
                    await image.decode();
                    if (this.$refs.cameraDialog?.open) await this.submitPhoto(image);
                } catch {
                    this.error = this.labels.photoUnreadable;
                } finally {
                    URL.revokeObjectURL(url);
                    this.starting = false;
                }
            },
            async submitPhoto(source) {
                if (this.busy) return;
                if (!navigator.geolocation || !window.isSecureContext) {
                    this.error = this.labels.secureLocation;
                    return;
                }
                this.busy = true;
                const canvas = this.$refs.canvas;
                try {
                    const width = source.videoWidth || source.naturalWidth;
                    const height = source.videoHeight || source.naturalHeight;
                    const scale = Math.min(1, 960 / Math.max(width, height));
                    canvas.width = Math.round(width * scale);
                    canvas.height = Math.round(height * scale);
                    canvas.getContext('2d').drawImage(source, 0, 0, canvas.width, canvas.height);
                    const photo = canvas.toDataURL('image/jpeg', 0.8);
                    const position = await new Promise((resolve, reject) => navigator.geolocation.getCurrentPosition(resolve, reject, {
                        enableHighAccuracy: true, maximumAge: 0, timeout: 20000,
                    }));
                    if (!this.$refs.cameraDialog?.open) return;
                    if (!this.request || this.request.kind !== this.kind) {
                        this.request = { kind: this.kind, id: crypto.randomUUID() };
                    }
                    const gps = { latitude: position.coords.latitude, longitude: position.coords.longitude, accuracy: position.coords.accuracy, captured_at: position.timestamp };
                    if ($wire.geofenceEnabled && this.location !== null && this.location !== '') gps.location_id = Number(this.location);
                    await $wire.punch({ kind: this.kind, request_id: this.request.id, ...gps, photo });
                } catch (error) {
                    this.error = error.code === 1
                        ? this.labels.locationPermission
                        : this.labels.submitError;
                } finally {
                    canvas.width = 0;
                    canvas.height = 0;
                    this.busy = false;
                }
            },
            showReceipt(detail) {
                if (!this.$refs.cameraDialog.open) return;
                this.receipt = detail;
                this.request = null;
                this.stopPreview();
                this.$refs.cameraDialog.close();
                this.$refs.successDialog.showModal();
            },
            destroy() { this.stopPreview(); }
        }"
        x-on:portal-punch-receipt.window="showReceipt($event.detail)"
        x-on:portal-punch-rejected.window="if ($refs.cameraDialog.open) { error = $event.detail.message; request = null }"
        x-on:pagehide.window="stopPreview()"
        x-on:visibilitychange.document="if (document.hidden) stopPreview()">
            @if ($this->geofenceEnabled)
                <div class="ess-punch__location">
                    {{ $this->form }}
                    <p class="ess-punch__hint">{{ __('employee-punch.radius') }}</p>
                </div>
            @endif

            <div class="ess-punch__choices">
                <x-filament::button type="button" class="ess-punch__touch" :icon="\Filament\Support\Icons\Heroicon::OutlinedArrowRightEndOnRectangle" x-on:click="openCamera('in')">
                    {{ __('employee-punch.checkIn') }}
                </x-filament::button>
                <x-filament::button type="button" class="ess-punch__touch" color="gray" :icon="\Filament\Support\Icons\Heroicon::OutlinedArrowLeftStartOnRectangle" x-on:click="openCamera('out')">
                    {{ __('employee-punch.checkOut') }}
                </x-filament::button>
            </div>

            <p class="ess-punch__hint">{{ __('employee-punch.requirements') }}</p>

            <dialog x-ref="cameraDialog" wire:ignore.self aria-label="{{ __('employee-punch.cameraDialog') }}"
                x-on:close="stopPreview()" x-on:cancel="if (busy) $event.preventDefault()"
                class="ess-punch__sheet">
                <div class="ess-punch__symbol" aria-hidden="true">
                    <x-filament::icon :icon="\Filament\Support\Icons\Heroicon::OutlinedCamera" />
                </div>
                <h2 class="ess-punch__title" x-text="kind === 'in' ? labels.checkInPhoto : labels.checkOutPhoto"></h2>
                <p x-show="nativeCamera" class="ess-punch__hint">{{ __('employee-punch.phoneHelp') }}</p>
                <input type="file" accept="image/*" capture="user" x-ref="cameraInput" x-on:change="phonePhoto($event)" hidden>
                <video x-show="!nativeCamera" x-ref="video" autoplay playsinline muted
                    class="ess-punch__preview"
                    aria-label="{{ __('employee-punch.preview') }}"></video>
                <canvas x-ref="canvas" hidden></canvas>
                <p x-show="starting || busy" x-text="busy ? labels.submitting : labels.cameraStarting" class="ess-punch__hint" role="status"></p>
                <p x-show="error" x-text="error" class="ess-punch__error" role="alert"></p>
                <div class="ess-punch__actions">
                    <x-filament::button type="button" class="ess-punch__touch" x-show="nativeCamera || ready" x-on:click="capture()" x-bind:disabled="busy || starting">
                        <span x-text="nativeCamera ? labels.phoneCamera : labels.capture"></span>
                    </x-filament::button>
                    <x-filament::button type="button" class="ess-punch__touch" x-show="!nativeCamera && !ready && !starting" x-on:click="startPreview()">{{ __('employee-punch.openCamera') }}</x-filament::button>
                    <x-filament::button type="button" class="ess-punch__touch" color="gray" x-on:click="$refs.cameraDialog.close()" x-bind:disabled="busy">{{ __('employee-punch.cancel') }}</x-filament::button>
                </div>
            </dialog>

            <dialog x-ref="successDialog" wire:ignore.self aria-label="{{ __('employee-punch.receipt') }}"
                class="ess-punch__sheet ess-punch__sheet--receipt">
                <div class="ess-punch__symbol" aria-hidden="true">
                    <x-filament::icon :icon="\Filament\Support\Icons\Heroicon::OutlinedCheckCircle" />
                </div>
                <h2 class="ess-punch__title" x-text="receipt?.kind === 'in' ? labels.checkInReceipt : labels.checkOutReceipt"></h2>
                <p class="ess-punch__time" x-text="receipt?.time"></p>
                <p class="ess-punch__hint" x-text="receipt?.state === 'approved' ? labels.approved : (receipt?.state === 'rejected' ? labels.rejected : labels.pending)"></p>
                <div class="ess-punch__actions">
                    <x-filament::button type="button" class="ess-punch__touch" x-on:click="$refs.successDialog.close()">{{ __('employee-punch.done') }}</x-filament::button>
                </div>
            </dialog>

            @if ($last = $this->lastPunch())
                <div class="ess-punch__last">
                    <p class="ess-punch__hint">{{ __('employee-punch.lastRequest') }}</p>
                    <p class="ess-punch__detail">{{ __($last->kind === 'in' ? 'employee-punch.checkIn' : 'employee-punch.checkOut') }} · {{ $last->punched_at->format('d M, h:i A') }} · {{ ucfirst($last->state) }}@if (filled($last->evidence['location_name'] ?? null)) · {{ $last->evidence['location_name'] }}@endif</p>
                </div>
            @endif
        </div>
    </x-filament::section>
</x-filament-widgets::widget>
