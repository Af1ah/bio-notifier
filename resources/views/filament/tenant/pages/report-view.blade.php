<div class="overflow-x-auto">
    <div class="mb-4">
        <h3 class="text-lg font-medium leading-6 text-gray-900 dark:text-white">
            Report: {{ $report->name }} ({{ ucfirst($report->type) }})
        </h3>
        <p class="mt-1 text-sm text-gray-500 dark:text-gray-400">
            Date Range: {{ ucfirst($report->date_range) }} | Status: {{ ucfirst($report->status) }}
        </p>
    </div>

    <table class="min-w-full divide-y divide-gray-200 dark:divide-white/5">
        <thead class="bg-gray-50 dark:bg-white/5">
            <tr>
                <th scope="col" class="px-3 py-3.5 text-left text-sm font-semibold text-gray-900 dark:text-white">User</th>
                @for ($i = 1; $i <= 7; $i++)
                    <th scope="col" class="px-3 py-3.5 text-left text-sm font-semibold text-gray-900 dark:text-white">
                        {{ now()->addDays($i)->format('D') }}<br>
                        <span class="text-xs font-normal text-gray-500">{{ now()->addDays($i)->format('M d') }}</span>
                    </th>
                @endfor
                <th scope="col" class="px-3 py-3.5 text-left text-sm font-semibold text-gray-900 dark:text-white">Total Hrs</th>
                <th scope="col" class="px-3 py-3.5 text-left text-sm font-semibold text-gray-900 dark:text-white">Present</th>
                <th scope="col" class="px-3 py-3.5 text-left text-sm font-semibold text-gray-900 dark:text-white">Absent</th>
            </tr>
        </thead>
        <tbody class="divide-y divide-gray-200 dark:divide-white/5 bg-white dark:bg-gray-900">
            @php
                $users = \App\Models\User::take(5)->get();
                if ($users->isEmpty()) {
                    $users = collect([(object)['name' => 'Demo User']]);
                }
            @endphp
            @foreach ($users as $user)
            <tr>
                <td class="whitespace-nowrap px-3 py-4 text-sm text-gray-900 dark:text-gray-300 font-medium">
                    {{ $user->name }}
                </td>
                @for ($i = 1; $i <= 7; $i++)
                    <td class="whitespace-nowrap px-3 py-4 text-sm text-gray-500 dark:text-gray-400">
                        @if(rand(0, 1))
                            <span class="inline-flex items-center rounded-md bg-green-50 px-2 py-1 text-xs font-medium text-green-700 ring-1 ring-inset ring-green-600/20">8h</span>
                        @else
                            <span class="inline-flex items-center rounded-md bg-red-50 px-2 py-1 text-xs font-medium text-red-700 ring-1 ring-inset ring-red-600/10">A</span>
                        @endif
                    </td>
                @endfor
                <td class="whitespace-nowrap px-3 py-4 text-sm text-gray-900 dark:text-white font-bold">40h</td>
                <td class="whitespace-nowrap px-3 py-4 text-sm text-green-600 font-bold">5</td>
                <td class="whitespace-nowrap px-3 py-4 text-sm text-red-600 font-bold">2</td>
            </tr>
            @endforeach
        </tbody>
    </table>
</div>
