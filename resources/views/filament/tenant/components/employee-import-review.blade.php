<div class="space-y-3">
    <p class="text-sm text-gray-500 dark:text-gray-400">Amounts in {{ \App\Services\Support\TenantCurrency::get() }}. Blank cells keep existing values.</p>
    <div style="max-height: 24rem; overflow: auto;" tabindex="0" role="region" aria-label="Employee import review">
        <table class="fi-ta-table">
            <caption class="sr-only">Employees to import</caption>
            <thead>
                <tr>
                    <th scope="col" class="fi-ta-header-cell">Row</th>
                    <th scope="col" class="fi-ta-header-cell">Action</th>
                    @foreach ($review['columns'] ?? [] as $label)
                        <th scope="col" class="fi-ta-header-cell">{{ $label }}</th>
                    @endforeach
                </tr>
            </thead>
            <tbody>
                @foreach ($review['rows'] ?? [] as $row)
                    <tr class="fi-ta-row">
                        <td class="fi-ta-cell p-3">{{ $row['line'] }}</td>
                        <td class="fi-ta-cell p-3">{{ $row['action'] }}</td>
                        @foreach ($review['columns'] ?? [] as $field => $label)
                            <td class="fi-ta-cell p-3" style="white-space: nowrap;">{{ $row['values'][$field] ?? '—' }}</td>
                        @endforeach
                    </tr>
                @endforeach
            </tbody>
        </table>
    </div>
</div>
