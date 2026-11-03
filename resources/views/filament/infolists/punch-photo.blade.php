<x-dynamic-component :component="$getEntryWrapperView()" :entry="$entry">
    <div x-data="{
        photo: null, error: '', disposed: false,
        async init() {
            try {
                // Fetch first so older service workers never cache a private image request.
                const response = await fetch(@js($getRecord()->photo_url), { credentials: 'same-origin', cache: 'no-store', headers: { Accept: 'image/jpeg' } });
                if (!response.ok || !response.headers.get('content-type')?.startsWith('image/jpeg')) throw new Error('Photo unavailable');
                const blob = await response.blob();
                if (!this.disposed) this.photo = URL.createObjectURL(blob);
            } catch (error) { this.error = 'Photo unavailable. Do not approve this request.'; }
        },
        destroy() { this.disposed = true; if (this.photo) URL.revokeObjectURL(this.photo); }
    }">
        <img x-cloak x-show="photo" x-bind:src="photo" alt="Photo captured with the punch request" style="max-height: 18rem; max-width: 100%; border-radius: .75rem; object-fit: contain;">
        <p x-show="!photo && !error" role="status" class="text-sm text-gray-500">Loading photo…</p>
        <p x-cloak x-show="error" x-text="error" role="alert" class="text-sm text-danger-600"></p>
    </div>
</x-dynamic-component>
