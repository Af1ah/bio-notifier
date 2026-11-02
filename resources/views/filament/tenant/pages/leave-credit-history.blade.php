<div class="space-y-4">
@isset($balance)
    <p class="text-sm">Carried: {{ $balance['carried_half_units'] / 2 }} days · Used: {{ $balance['consumed_half_units'] / 2 }} days · Expiring soon: {{ $balance['expiring_half_units'] / 2 }} days</p>
@endisset
@forelse ($lots as $lot)
    <section class="rounded-lg border p-3">
        <p><strong>{{ ucfirst($lot->source_kind) }}: {{ $lot->granted_half_units / 2 }} days</strong></p>
        <p>Available {{ $lot->available_on->format('d M Y') }} · expires {{ $lot->expires_on->format('d M Y') }}</p>
        <table class="w-full text-sm"><thead><tr><th>Date</th><th>Action</th><th>Days</th><th>Reason</th></tr></thead>
        <tbody>@foreach ($lot->entries as $entry)
            <tr><td>{{ $entry->effective_on->format('d M Y') }}</td><td>{{ str($entry->kind)->replace('_', ' ')->title() }}</td><td>{{ $entry->half_units / 2 }}</td><td>{{ $entry->reason }}</td></tr>
        @endforeach</tbody></table>
    </section>
@empty
    <p>No credits recorded for this leave type.</p>
@endforelse
</div>
