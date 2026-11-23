<span class="attendance-report-hours attendance-report-hours--{{ $hours['color'] ?? 'gray' }}"
    title="{{ $hours['label'] ?? 'Hours' }} · {{ $hours['clock'] }}h"
    aria-label="{{ $hours['label'] ?? 'Hours' }}: {{ $hours['clock'] }}h">
    {{ $hours['clock'] }}h
</span>
