@php
    $path = (string) $getRecord()->ai_face_photo_path;
    $disk = \Illuminate\Support\Facades\Storage::disk('local');
    $photo = null;
    if (str_starts_with($path, 'biometric-photos/') && ! str_contains($path, '..') && $disk->exists($path) && $disk->size($path) <= 786432) {
        $photo = 'data:image/jpeg;base64,'.base64_encode($disk->get($path));
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
