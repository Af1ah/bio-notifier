<x-dynamic-component :component="$getFieldWrapperView()" :field="$field">
    <div x-data="{
        stream: null, preview: null, busy: false, error: '', disposed: false,
        stop() { this.stream?.getTracks().forEach(track => track.stop()); this.stream = null; },
        destroy() { this.disposed = true; this.stop(); if (this.preview) URL.revokeObjectURL(this.preview); },
        async open() {
            this.error = '';
            if (!navigator.mediaDevices?.getUserMedia) { this.$refs.fallback.click(); return; }
            this.busy = true;
            try {
                const stream = await navigator.mediaDevices.getUserMedia({ video: { facingMode: 'user', width: {ideal: 1280}, height: {ideal: 1280} }, audio: false });
                if (this.disposed) { stream.getTracks().forEach(track => track.stop()); return; }
                this.stop(); this.stream = stream;
                await this.$nextTick(); this.$refs.video.srcObject = stream; await this.$refs.video.play();
            } catch (e) { this.stop(); this.error = 'Unable to open the camera. Allow camera access or select Upload photo.'; }
            finally { this.busy = false; }
        },
        async encode(source, width, height) {
            this.busy = true; this.error = '';
            try {
                if (!width || !height) throw new Error('Camera is not ready.');
                const canvas = document.createElement('canvas');
                let scale = Math.min(1, 1024 / Math.max(width, height));
                let blob = null;
                for (let pass = 0; pass < 4; pass++) {
                    canvas.width = Math.max(1, Math.round(width * scale)); canvas.height = Math.max(1, Math.round(height * scale));
                    const context = canvas.getContext('2d'); context.fillStyle = '#fff'; context.fillRect(0, 0, canvas.width, canvas.height);
                    context.drawImage(source, 0, 0, canvas.width, canvas.height);
                    for (const quality of [0.9, 0.8, 0.65, 0.5]) {
                        blob = await new Promise(resolve => canvas.toBlob(resolve, 'image/jpeg', quality));
                        if (blob && blob.size <= 768 * 1024) break;
                    }
                    if (blob && blob.size <= 768 * 1024) break;
                    scale *= 0.75;
                }
                if (!blob || blob.size > 768 * 1024) throw new Error('Unable to fit the photo within 768 KB. Try again.');
                const data = await new Promise((resolve, reject) => { const reader = new FileReader(); reader.onload = () => resolve(reader.result); reader.onerror = reject; reader.readAsDataURL(blob); });
                await this.$wire.set(@js($getStatePath()), data);
                if (this.preview) URL.revokeObjectURL(this.preview);
                this.preview = URL.createObjectURL(blob); this.stop();
            } catch (e) { this.error = e.message || 'Unable to prepare the photo. Try again.'; }
            finally { this.busy = false; }
        },
        async capture() { await this.encode(this.$refs.video, this.$refs.video.videoWidth, this.$refs.video.videoHeight); },
        async fromFile(event) {
            const file = event.target.files?.[0]; if (!file) return;
            let bitmap;
            try { bitmap = await createImageBitmap(file); await this.encode(bitmap, bitmap.width, bitmap.height); }
            catch (e) { this.error = 'Unable to read this photo. Choose a JPEG using Upload photo.'; }
            finally { bitmap?.close(); event.target.value = ''; }
        }
    }" x-on:close-modal.window="stop()" class="space-y-3">
        <p class="text-sm text-gray-500">Face the camera in good light. Your photo is saved as a JPEG, resized and compressed to 768 KB or less.</p>
        <video x-ref="video" x-show="stream" autoplay muted playsinline style="width:100%;max-height:320px;border-radius:8px" aria-label="Camera preview"></video>
        <template x-if="preview && !stream"><img :src="preview" alt="Captured AI Face photo" style="max-height:240px;max-width:100%;border-radius:8px"></template>
        <input x-ref="fallback" type="file" accept="image/*" capture="user" class="hidden" x-on:change="fromFile($event)" aria-label="Take a face photo">
        <div class="flex flex-wrap gap-2">
            <x-filament::button type="button" x-show="!stream" x-on:click="open()" x-bind:disabled="busy"><span x-text="preview ? 'Retake photo' : 'Take photo'"></span></x-filament::button>
            <x-filament::button type="button" x-show="stream" x-on:click="capture()" x-bind:disabled="busy">Use photo</x-filament::button>
            <x-filament::button type="button" color="gray" x-show="stream" x-on:click="stop()">Cancel camera</x-filament::button>
        </div>
        <p x-show="busy" class="text-sm text-gray-500" role="status">Preparing photo…</p>
        <p x-show="error" x-text="error" class="text-sm text-danger-600" role="alert"></p>
    </div>
</x-dynamic-component>
