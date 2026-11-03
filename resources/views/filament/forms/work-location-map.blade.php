@assets
    @vite('resources/js/work-location-map.js')
@endassets
<x-dynamic-component :component="$getFieldWrapperView()" :field="$field">
    <div x-data="{
        latitude: $wire.$entangle('{{ $getStatePath() }}'.replace(/map$/, 'latitude')),
        longitude: $wire.$entangle('{{ $getStatePath() }}'.replace(/map$/, 'longitude')),
        radius: $wire.$entangle('{{ $getStatePath() }}'.replace(/map$/, 'radius_meters')),
        error: '', locating: false, disposed: false, dispose: null, center: null,
        async init() {
            try {
                const { mountPicker } = await import(@js(\Illuminate\Support\Facades\Vite::asset('resources/js/work-location-map.js')));
                if (this.disposed || !this.$el.isConnected) return;
                const picker = mountPicker(this.$refs.map, {
                    latitude: this.latitude, longitude: this.longitude, radius: this.radius,
                    center: @js([config('maps.default_latitude'), config('maps.default_longitude')]),
                    tileUrl: @js(config('maps.tile_url')),
                    onChange: point => { this.latitude = Number(point.lat.toFixed(7)); this.longitude = Number(point.lng.toFixed(7)); },
                });
                this.dispose = () => picker.destroy();
                this.center = (lat, lng) => picker.center(lat, lng);
                ['latitude', 'longitude', 'radius'].forEach(key => this.$watch(key, () => picker.sync(this.latitude, this.longitude, this.radius)));
            } catch (error) { this.error = 'Map unavailable. You can still enter coordinates above.'; }
        },
        locate() {
            this.error = '';
            if (!navigator.geolocation) { this.error = 'Location is unavailable in this browser.'; return; }
            this.locating = true;
            navigator.geolocation.getCurrentPosition(position => {
                if (this.disposed) return;
                this.latitude = Number(position.coords.latitude.toFixed(7));
                this.longitude = Number(position.coords.longitude.toFixed(7));
                this.center?.(this.latitude, this.longitude); this.locating = false;
            }, () => { this.error = 'Allow location access and try again.'; this.locating = false; }, { enableHighAccuracy: true, maximumAge: 0, timeout: 20000 });
        },
        destroy() { this.disposed = true; this.dispose?.(); }
    }">
        <div class="mb-3 flex flex-wrap items-center gap-3">
            <x-filament::button type="button" color="gray" x-on:click="locate()" x-bind:disabled="locating">Use my location</x-filament::button>
            <a class="text-sm underline" target="_blank" rel="noopener noreferrer" x-show="latitude !== null && latitude !== '' && longitude !== null && longitude !== ''"
                x-bind:href="'https://www.google.com/maps/search/?api=1&query=' + encodeURIComponent(latitude + ',' + longitude)">Open in Google Maps</a>
        </div>
        <p class="mb-2 text-sm text-gray-500">Tap the map or drag the pin. The circle shows the allowed radius.</p>
        <div wire:ignore x-ref="map" style="height: 20rem; width: 100%; border-radius: .75rem; isolation: isolate;" aria-label="Work location map"></div>
        <p x-cloak x-show="error" x-text="error" role="alert" class="mt-2 text-sm text-danger-600"></p>
    </div>
</x-dynamic-component>
