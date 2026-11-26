@props(['label', 'value'])

<div {{ $attributes->class(['portal-metric']) }}>
    <dt>{{ $label }}</dt>
    <dd>{{ $value }}</dd>
</div>
