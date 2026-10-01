@php
    $month = \Carbon\Carbon::create($this->year, $this->month, 1);
    $results = \App\Models\AttendanceDay::with('occurrences.schedule')
        ->where('user_id', $record->id)
        ->whereBetween('work_date', [$month->copy()->startOfMonth(), $month->copy()->endOfMonth()])
        ->get()
        ->keyBy(fn ($day) => $day->work_date->format('Y-m-d'));
    $logs = $record->attendanceLogs()
        ->whereBetween('punched_at', [$month->copy()->startOfMonth(), $month->copy()->endOfMonth()])
        ->get()
        ->groupBy(fn ($log) => $log->punched_at->format('Y-m-d'));

    $weeks = [];
    $week = array_fill(0, $month->dayOfWeek, null);

    for ($day = 1; $day <= $month->daysInMonth; $day++) {
        $week[] = $day;

        if (count($week) === 7) {
            $weeks[] = $week;
            $week = [];
        }
    }

    if ($week) {
        while (count($week) < 7) {
            $week[] = null;
        }

        $weeks[] = $week;
    }
@endphp

<div x-data="{ details: null }" class="user-attendance-calendar">
<style>
    .user-attendance-calendar { margin-top: 1rem; }
    .user-attendance-calendar__scroll { overflow-x: auto; padding: .25rem; }
    .user-attendance-calendar__toolbar { display: grid; grid-template-columns: 2.5rem minmax(0, 1fr) 2.5rem; align-items: center; gap: .75rem; margin-bottom: 1rem; }
    .user-attendance-calendar__title { margin: 0; color: inherit; font-size: 1.125rem; font-weight: 700; text-align: center; }
    .user-attendance-calendar__table { width: 100%; min-width: 42rem; table-layout: fixed; border-collapse: separate; border-spacing: .5rem; }
    .user-attendance-calendar__table th { padding: 0 0 .25rem; color: rgb(156 163 175); font-size: .75rem; font-weight: 700; text-align: center; }
    .user-attendance-calendar__table td { width: 14.285%; height: 5.5rem; padding: .5rem; vertical-align: top; }
    .user-attendance-calendar__empty-day { border-radius: .5rem; background: rgb(255 255 255 / .05); }
    .user-attendance-calendar__day { border: 1px solid rgb(0 0 0 / .08); border-radius: .5rem; color: rgb(17 24 39); cursor: pointer; text-align: center; transition: filter .15s ease, transform .15s ease; }
    .user-attendance-calendar__day:hover { filter: brightness(.96); transform: translateY(-1px); }
    .user-attendance-calendar__date { font-weight: 700; line-height: 1.1; }
    .user-attendance-calendar__status { margin-top: .25rem; font-size: .75rem; line-height: 1.1; }
    .user-attendance-calendar__worked { margin-top: .2rem; color: rgb(55 65 81); font-size: .6875rem; line-height: 1.1; }
</style>

    <div class="user-attendance-calendar__scroll">
        <div class="user-attendance-calendar__toolbar">
            <x-filament::icon-button icon="heroicon-m-chevron-left" wire:click="previousMonth" />
            <h3 class="user-attendance-calendar__title">{{ $month->format('F Y') }}</h3>
            <x-filament::icon-button icon="heroicon-m-chevron-right" wire:click="nextMonth" />
        </div>

        <table class="user-attendance-calendar__table">
            <thead><tr>
                @foreach (['Sun', 'Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat'] as $name)
                    <th scope="col">{{ $name }}</th>
                @endforeach
            </tr></thead>
            <tbody>
                @foreach ($weeks as $week)
                    <tr>
                        @foreach ($week as $number)
                            @if (! $number)
                                <td class="user-attendance-calendar__empty-day"></td>
                            @else
                                @php
                                    $key = $month->format('Y-m-').str_pad($number, 2, '0', STR_PAD_LEFT);
                                    $result = $results->get($key);
                                    $dayLogs = $logs->get($key, collect());
                                    $status = $result?->status ?? 'not_calculated';
                                    $colors = match ($status) {
                                        'present' => '#dcfce7', 'half_day' => '#fef3c7', 'absent' => '#fee2e2',
                                        'off', 'holiday', 'no_shift' => '#e5e7eb', default => '#f3f4f6',
                                    };
                                    $label = match ($status) {
                                        'present' => 'Present', 'half_day' => 'Half day', 'absent' => 'Absent',
                                        'off', 'holiday' => 'Off', 'no_shift' => 'No shift', default => 'Not calculated',
                                    };
                                    $workedMinutes = $result?->approved_worked_minutes ?: $result?->candidate_worked_minutes;
                                    $payload = [
                                        'date' => \Carbon\Carbon::parse($key)->format('M d, Y'), 'status' => $label,
                                        'worked' => $result ? sprintf('%dh %dm', intdiv($workedMinutes, 60), $workedMinutes % 60) : '—',
                                        'ot' => $result?->approved_overtime_minutes ?? 0,
                                        'shifts' => $result?->occurrences->map(fn ($occurrence) => [
                                            'name' => $occurrence->schedule?->name, 'in' => $occurrence->first_in_at?->format('h:i A'),
                                            'out' => ($occurrence->last_out_at ?? $occurrence->assumed_out_at)?->format('h:i A'), 'status' => $occurrence->status,
                                        ])->values()->all() ?? [],
                                        'logs' => $dayLogs->map(fn ($log) => ['time' => $log->punched_at->format('h:i A'), 'status' => $log->status_label])->values()->all(),
                                    ];
                                @endphp
                                <td class="user-attendance-calendar__day" style="background-color: {{ $colors }}" @click='details = @json($payload); $dispatch("open-modal", { id: "attendance-day-detail" })'>
                                    <div class="user-attendance-calendar__date">{{ $number }}</div>
                                    <div class="user-attendance-calendar__status">{{ $label }}</div>
                                    @if ($result)<div class="user-attendance-calendar__worked">{{ $payload['worked'] }}</div>@endif
                                </td>
                            @endif
                        @endforeach
                    </tr>
                @endforeach
            </tbody>
        </table>
    </div>

    <x-filament::modal id="attendance-day-detail" width="lg">
        <x-slot name="heading"><span x-text="details?.date"></span></x-slot>
        <div x-show="details">
            <p class="font-semibold" x-text="details?.status+' · '+details?.worked"></p>
            <p class="text-sm text-gray-500">Approved OT: <span x-text="details?.ot"></span> minutes</p>
            <template x-for="shift in details?.shifts || []"><div class="mt-3 rounded border p-3 text-sm"><b x-text="shift.name"></b><div><span x-text="shift.in || 'Missing IN'"></span> — <span x-text="shift.out || 'Missing OUT'"></span></div><span x-text="shift.status"></span></div></template>
            <h4 class="mt-4 font-semibold">Raw punches</h4>
            <template x-for="log in details?.logs || []"><div class="text-sm"><span x-text="log.time"></span> · <span x-text="log.status"></span></div></template>
        </div>
    </x-filament::modal>
</div>
