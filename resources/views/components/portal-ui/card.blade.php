@props(['variant' => 'default'])

<section {{ $attributes->class(['portal-card', 'portal-card--'.$variant]) }}>
    {{ $slot }}
</section>
