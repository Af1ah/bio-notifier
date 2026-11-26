@props(['href' => null, 'icon' => null, 'variant' => 'secondary', 'active' => false])

<x-filament::button
    :tag="$href ? 'a' : 'button'"
    :href="$href"
    :type="$href ? null : 'button'"
    :icon="$icon"
    :color="$variant === 'primary' ? 'primary' : 'gray'"
    :aria-current="$active ? 'page' : null"
    {{ $attributes->class(['portal-button', 'portal-button--'.$variant, 'portal-button--active' => $active]) }}
>
    {{ $slot }}
</x-filament::button>
