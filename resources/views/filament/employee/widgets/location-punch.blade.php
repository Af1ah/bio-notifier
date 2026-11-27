<x-filament-widgets::widget>
    <link rel="stylesheet" href="{{ asset('css/ess-punch.css') }}">
    <div class="portal-stack" id="manual-punch">
        <div class="ess-punch portal-stack" x-data="{
            labels: @js(__('employee-punch')),
            location: '', note: '', step: 'setup',
            busy: false, starting: false, error: '', request: null,
            nativeCamera: false, ready: false, stream: null, kind: null, receipt: null,
            gpsPromise: null, lastPosition: null,
            prepareLocation() {
                if (this.lastPosition && Date.now() - this.lastPosition.timestamp < 60000) return Promise.resolve(this.lastPosition);
                if (this.gpsPromise) return this.gpsPromise;
                if (!navigator.geolocation || !window.isSecureContext) return Promise.reject(new Error(this.labels.secureLocation));
                this.gpsPromise = new Promise((resolve, reject) => navigator.geolocation.getCurrentPosition(resolve, reject, {
                    enableHighAccuracy: true, maximumAge: 30000, timeout: 15000,
                })).then(position => { this.lastPosition = position; return position; })
                    .finally(() => { this.gpsPromise = null; });
                return this.gpsPromise;
            },
            openPunch(kind = 'in') {
                if (this.busy || this.starting) return;
                this.kind = kind;
                this.step = 'setup';
                this.error = '';
                this.nativeCamera = window.matchMedia('(pointer: coarse)').matches;
                this.$refs.cameraDialog.showModal();
                this.prepareLocation().catch(() => {});
            },
            continueToCamera() {
                this.step = 'camera';
                if (this.nativeCamera) this.capture();
                else this.$nextTick(() => this.startPreview());
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
                    const position = await this.prepareLocation();
                    if (!this.$refs.cameraDialog?.open) return;
                    if (!this.request || this.request.kind !== this.kind) {
                        this.request = { kind: this.kind, id: crypto.randomUUID() };
                    }
                    const gps = { latitude: position.coords.latitude, longitude: position.coords.longitude, accuracy: position.coords.accuracy, captured_at: position.timestamp };
                    if ($wire.geofenceEnabled && this.location !== '') gps.location_id = Number(this.location);
                    await $wire.punch({ kind: this.kind, request_id: this.request.id, ...gps, photo, note: this.note });
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
                this.location = '';
                this.note = '';
                this.stopPreview();
                this.$refs.cameraDialog.close();
                this.$refs.successDialog.showModal();
            },
            destroy() { this.stopPreview(); }
        }"
        x-on:ess-open-manual-punch.window="openPunch()"
        x-on:portal-punch-receipt.window="showReceipt($event.detail)"
        x-on:portal-punch-rejected.window="if ($refs.cameraDialog.open) { error = $event.detail.message; request = null }"
        x-on:pagehide.window="stopPreview()"
        x-on:visibilitychange.document="if (document.hidden) stopPreview()">
            <div class="portal-row">
                <div>
                    <p class="portal-kicker portal-number">{{ $today->translatedFormat('D, d M Y') }}</p>
                    <h1 class="portal-title">{{ $employee->name }}</h1>
                </div>
            </div>

            <x-portal-ui.card variant="hero" class="portal-stack">
                <div class="portal-row">
                    <div class="portal-clock" x-data="{
                        clock: @js($today->format('h:i A')), timer: null,
                        init() {
                            const format = new Intl.DateTimeFormat('en', { timeZone: @js($timezone), hour: '2-digit', minute: '2-digit', hour12: true });
                            const tick = () => { this.clock = format.format(new Date()) };
                            tick();
                            this.timer = setInterval(tick, 1000);
                        },
                        destroy() { clearInterval(this.timer) }
                    }" x-text="clock">{{ $today->format('h:i A') }}</div>
                </div>

                <dl class="portal-metrics portal-metrics--ruled">
                    <x-portal-ui.metric :label="__('portal-ui.check_in')" :value="$firstIn" />
                    <x-portal-ui.metric :label="__('portal-ui.check_out')" :value="$lastOut" />
                    <x-portal-ui.metric :label="__('portal-ui.logged')" :value="$logged" />
                </dl>

                <div class="portal-punch-actions">
                    <x-portal-ui.button variant="primary" class="ess-punch__touch" :icon="\Filament\Support\Icons\Heroicon::OutlinedCamera" x-on:click="openPunch()">
                        {{ __('portal-ui.manual_punch') }}
                    </x-portal-ui.button>
                    <x-portal-ui.button variant="icon" :href="\App\Filament\Employee\Pages\Calendar::getUrl(panel: 'employee')"
                        :icon="\Filament\Support\Icons\Heroicon::OutlinedCalendarDays" :aria-label="__('portal-ui.calendar')" wire:navigate />
                </div>
            </x-portal-ui.card>

            <dialog x-ref="cameraDialog" wire:ignore.self aria-label="{{ __('employee-punch.cameraDialog') }}"
                x-on:close="stopPreview()" x-on:cancel="if (busy) $event.preventDefault()"
                class="ess-punch__sheet">
                <div class="portal-row">
                    <div>
                        <p class="portal-kicker">{{ __('portal-ui.manual_punch') }}</p>
                        <h2 class="ess-punch__title" x-text="step === 'setup' ? labels.choosePunch : (kind === 'in' ? labels.checkInPhoto : labels.checkOutPhoto)"></h2>
                    </div>
                    <x-portal-ui.button variant="icon" :aria-label="__('employee-punch.cancel')" :icon="\Filament\Support\Icons\Heroicon::OutlinedXMark"
                        x-on:click="$refs.cameraDialog.close()" x-bind:disabled="busy" />
                </div>

                <div x-show="step === 'setup'" class="ess-punch__setup">
                    <div class="ess-punch__direction">
                        @foreach (['in' => 'checkIn', 'out' => 'checkOut'] as $direction => $label)
                            <button type="button" x-on:click="kind = @js($direction)" x-bind:aria-pressed="kind === @js($direction)">
                                {{ __('employee-punch.'.$label) }}
                            </button>
                        @endforeach
                    </div>

                    @if ($this->geofenceEnabled)
                        <fieldset class="ess-punch__sites">
                            <legend>{{ __('employee-punch.location') }}</legend>
                            <div class="ess-punch__site-list">
                                @foreach (['' => __('employee-punch.noSite')] + $locations->all() as $id => $name)
                                    <label class="ess-punch__site">
                                        <span>{{ $name }}@if ($id === '')<small>{{ __('employee-punch.autoGps') }}</small>@endif</span>
                                        <input type="radio" name="punch-site" value="{{ $id }}" x-model="location">
                                    </label>
                                @endforeach
                            </div>
                        </fieldset>
                    @endif

                    <label class="ess-punch__note">
                        {{ __('employee-punch.note') }} <span>{{ __('employee-punch.optional') }}</span>
                        <textarea x-model="note" maxlength="500" rows="2" placeholder="{{ __('employee-punch.notePlaceholder') }}"></textarea>
                    </label>
                    <x-portal-ui.button class="ess-punch__touch" variant="primary" :icon="\Filament\Support\Icons\Heroicon::OutlinedCamera"
                        x-on:click="continueToCamera()">{{ __('employee-punch.continueCamera') }}</x-portal-ui.button>
                </div>

                <div x-show="step === 'camera'" class="ess-punch__setup">
                    <p class="ess-punch__hint">{{ __('employee-punch.requirements') }}</p>
                    <input type="file" accept="image/*" capture="user" x-ref="cameraInput" x-on:change="phonePhoto($event)" hidden>
                    <video x-show="!nativeCamera" x-ref="video" autoplay playsinline muted
                        class="ess-punch__preview"
                        aria-label="{{ __('employee-punch.preview') }}"></video>
                    <canvas x-ref="canvas" hidden></canvas>
                    <p x-show="starting || busy" x-text="busy ? labels.submitting : labels.cameraStarting" class="ess-punch__hint" role="status"></p>
                    <p x-show="error" x-text="error" class="ess-punch__error" role="alert"></p>
                    <div class="ess-punch__actions">
                        <x-portal-ui.button class="ess-punch__touch" variant="primary" x-show="nativeCamera || ready" x-on:click="capture()" x-bind:disabled="busy || starting">
                            <span x-text="nativeCamera ? labels.phoneCamera : labels.capture"></span>
                        </x-portal-ui.button>
                        <x-portal-ui.button class="ess-punch__touch" variant="primary" x-show="!nativeCamera && !ready && !starting" x-on:click="startPreview()">{{ __('employee-punch.openCamera') }}</x-portal-ui.button>
                        <x-portal-ui.button class="ess-punch__touch" x-on:click="stopPreview(); step = 'setup'; error = ''" x-bind:disabled="busy || starting">{{ __('employee-punch.back') }}</x-portal-ui.button>
                    </div>
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
                    <x-portal-ui.button class="ess-punch__touch" variant="primary" x-on:click="$refs.successDialog.close()">{{ __('employee-punch.done') }}</x-portal-ui.button>
                </div>
            </dialog>

            @if ($last = $this->lastPunch())
                <x-portal-ui.punch-row :title="__($last->kind === 'in' ? 'portal-ui.check_in' : 'portal-ui.check_out')"
                    :at="$last->punched_at" :state="$last->state" :detail="$last->evidence['location_name'] ?? null"
                    :eyebrow="__('employee-punch.lastRequest')" :icon="\Filament\Support\Icons\Heroicon::OutlinedCamera" role="status" />
            @endif
        </div>
    </div>
</x-filament-widgets::widget>
