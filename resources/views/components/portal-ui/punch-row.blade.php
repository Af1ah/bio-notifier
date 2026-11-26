@props(['title', 'at', 'icon', 'detail' => null, 'state' => null, 'eyebrow' => null])

<x-portal-ui.card {{ $attributes->class('portal-punch-row') }}>
    <div class="portal-punch-row__icon" aria-hidden="true">
        <x-filament::icon :icon="$icon" />
    </div>
    <div class="portal-punch-row__body">
        @if ($eyebrow)<p class="portal-kicker">{{ $eyebrow }}</p>@endif
        <p class="portal-activity-title">{{ $title }}</p>
        @if ($detail)<p class="portal-kicker">{{ $detail }}</p>@endif
    </div>
    <div class="portal-punch-row__meta">
        <time class="portal-number" datetime="{{ $at->toIso8601String() }}">{{ $at->format('d M · h:i A') }}</time>
        @if (in_array($state, ['pending', 'approved', 'rejected'], true))
            <span class="portal-punch-row__state portal-punch-row__state--{{ $state }}">{{ __('portal-ui.punch_'.$state) }}</span>
        @endif
    </div>
</x-portal-ui.card>
