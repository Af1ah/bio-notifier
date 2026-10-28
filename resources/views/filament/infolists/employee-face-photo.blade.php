@php
    $path = (string) $getRecord()->ai_face_photo_path;
    $disk = \Illuminate\Support\Facades\Storage::disk('local');
    $photo = null;
    if ($path !== '') {
        try { $photo = 'data:image/jpeg;base64,'.base64_encode(\App\Services\Devices\PrivateBiometricPhoto::read($path)); }
        catch (\RuntimeException $exception) { $photo = null; }
    }
@endphp
<x-filament-infolists::entry-wrapper :entry="$entry">
    @if ($photo)
        <img src="{{ $photo }}" alt="Stored AI Face photo" style="max-height:240px;max-width:100%;border-radius:8px" />
        <p class="mt-2 text-sm text-gray-500">Saved JPEG photo. Fetching a face template does not refresh this image. The photo displayed on the device may be different.</p>
    @else
        <p class="text-sm text-gray-500">No face photo stored. Raw face templates cannot be displayed as a photograph.</p>
    @endif
</x-filament-infolists::entry-wrapper>
