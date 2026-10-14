<div class="space-y-4">
    @forelse ($rows as $row)
        <div class="rounded-lg border p-3"><pre class="whitespace-pre-wrap text-sm">{{ json_encode($row, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) }}</pre></div>
    @empty
        <p>No history recorded.</p>
    @endforelse
</div>
