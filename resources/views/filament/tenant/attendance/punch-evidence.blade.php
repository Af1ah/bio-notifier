<div class="space-y-2 text-sm">
    <p class="text-gray-500">Original device logs · {{ \App\Services\Support\TenantTimezone::get() }}</p>
    @forelse ($logs as $log)
        <div class="flex flex-wrap gap-x-3 gap-y-1">
            <span>{{ $log->punched_at->format('d M Y H:i:s') }}</span>
            <span>{{ $log->status_label }}</span>
            <span class="text-gray-500">{{ $log->device?->name ?? 'Device' }}</span>
        </div>
    @empty
        <p>No device punches for this employee and work date.</p>
    @endforelse
    @if ($truncated)
        <p>Showing the first 100 punches. Review Attendance Logs for the full history.</p>
    @endif
</div>
