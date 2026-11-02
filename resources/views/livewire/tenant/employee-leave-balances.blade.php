<div>
    <table class="w-full text-sm">
        <thead><tr><th class="p-2 text-left">Leave type</th><th class="p-2 text-right">Available days</th><th class="p-2 text-right">Reserved days</th><th></th></tr></thead>
        <tbody>
            @forelse ($balances as $row)
                <tr>
                    <td class="p-2">{{ $row['type']->name }}</td>
                    <td class="p-2 text-right">{{ $row['balance']['available_half_units'] / 2 }}</td>
                    <td class="p-2 text-right">{{ $row['balance']['reserved_half_units'] / 2 }}</td>
                    <td class="p-2 text-right"><x-filament::link tag="button" wire:click="showHistory({{ $row['type']->id }})">View history</x-filament::link></td>
                </tr>
            @empty
                <tr><td colspan="4" class="p-2">No paid leave types configured.</td></tr>
            @endforelse
        </tbody>
    </table>
    <x-filament::modal id="employee-leave-history-{{ $record->id }}" width="lg">
        <x-slot name="heading">Leave history</x-slot>
        @if ($historyType)
            @php($selected = $balances->first(fn ($row) => $row['type']->id === $historyType))
            @if ($selected)
                <p class="text-sm">Carried: {{ $selected['balance']['carried_half_units'] / 2 }} days · Used: {{ $selected['balance']['consumed_half_units'] / 2 }} days · Expiring soon: {{ $selected['balance']['expiring_half_units'] / 2 }} days</p>
            @endif
        @endif
        @include('filament.tenant.pages.leave-credit-history', ['lots' => $lots])
    </x-filament::modal>
</div>
