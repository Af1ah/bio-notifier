@php
    $currentMonth = \Carbon\Carbon::create($this->year, $this->month, 1);
    
    $logs = $record->attendanceLogs()
        ->whereMonth('punched_at', $this->month)
        ->whereYear('punched_at', $this->year)
        ->get()
        ->groupBy(fn($log) => $log->punched_at->format('Y-m-d'));

    // Fetch all applicable schedules
    $allSchedules = \App\Models\Schedule::where('status', true)
        ->where(function ($query) use ($record) {
            $query->where(function ($q) use ($record) {
                $q->where('target_type', \App\Models\TaskGroup::class)
                  ->whereIn('target_id', clone $record->taskGroups->pluck('id'));
            })
            ->orWhere(function ($q) use ($record) {
                $q->where('target_type', \App\Models\Department::class)
                  ->where('target_id', $record->department_id);
            })
            ->orWhere(function ($q) use ($record) {
                $q->where('target_type', \App\Models\Branch::class)
                  ->where('target_id', $record->branch_id);
            })
            ->orWhereNull('target_type');
        })
        ->get();

    $daysInMonth = $currentMonth->daysInMonth;
    $firstDayOfMonth = $currentMonth->copy()->startOfMonth()->dayOfWeek; // 0 (Sun) to 6 (Sat)
@endphp

<div x-data="{ openLogModal: false, selectedDate: '', selectedShift: '', selectedLogs: [] }" class="mt-4 overflow-x-auto w-full relative">
    <div style="margin-bottom: 1rem; display: flex; align-items: center; justify-content: space-between; font-weight: bold; font-size: 1.125rem;">
        <x-filament::icon-button
            icon="heroicon-m-chevron-left"
            wire:click="previousMonth"
            color="gray"
        />
        <span>{{ $currentMonth->format('F Y') }}</span>
        <x-filament::icon-button
            icon="heroicon-m-chevron-right"
            wire:click="nextMonth"
            color="gray"
        />
    </div>
    
    <table style="width: 100%; border-collapse: separate; border-spacing: 0.5rem; table-layout: fixed;">
        <thead>
            <tr>
                @foreach(['Sun', 'Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat'] as $dayName)
                    <th style="padding: 0.5rem; text-align: center; font-weight: 600; color: #6b7280; font-size: 0.875rem;">{{ $dayName }}</th>
                @endforeach
            </tr>
        </thead>
        <tbody>
            <tr>
            @for ($i = 0; $i < $firstDayOfMonth; $i++)
                <td style="padding: 0.5rem; border-radius: 0.5rem; border: 1px solid #e5e7eb; background-color: #f9fafb; opacity: 0.5;"></td>
            @endfor

            @php $currentDayOfWeek = $firstDayOfMonth; @endphp

            @for ($day = 1; $day <= $daysInMonth; $day++)
                @php
                    $dateString = $currentMonth->format('Y-m-') . str_pad($day, 2, '0', STR_PAD_LEFT);
                    $dateObj = \Carbon\Carbon::parse($dateString);
                    $dayOfWeek = $dateObj->dayOfWeek;
                    
                    $dayNames = ['sunday', 'monday', 'tuesday', 'wednesday', 'thursday', 'friday', 'saturday'];
                    $dayNameStr = $dayNames[$dayOfWeek];
                    
                    $dayLogs = $logs->get($dateString, collect());
                    $firstPunch = $dayLogs->sortBy('punched_at')->first();
                    
                    // Match best schedule
                    $matchedSchedule = null;
                    if ($firstPunch && $allSchedules->count() > 0) {
                        $minDiff = INF;
                        foreach ($allSchedules as $sched) {
                            // Check valid dates
                            if ($sched->valid_from && $dateObj->lt(\Carbon\Carbon::parse($sched->valid_from))) continue;
                            if ($sched->valid_to && $dateObj->gt(\Carbon\Carbon::parse($sched->valid_to))) continue;
                            
                            $rules = $sched->rules;
                            
                            if (isset($rules['weekly']['days'][$dayNameStr])) {
                                $dayConfig = $rules['weekly']['days'][$dayNameStr];
                                if ($dayConfig['is_working'] ?? false) {
                                    $schedStart = \Carbon\Carbon::parse($dateString . ' ' . ($dayConfig['start'] ?? '09:00'));
                                    $diff = abs($firstPunch->punched_at->diffInMinutes($schedStart));
                                    if ($diff < $minDiff) {
                                        $minDiff = $diff;
                                        $matchedSchedule = $sched;
                                    }
                                }
                            }
                        }
                    }
                    if (!$matchedSchedule) {
                        $matchedSchedule = $allSchedules->first(); // fallback
                    }

                    $rules = $matchedSchedule ? $matchedSchedule->rules : null;
                    $isWorkingDay = false;
                    $expectedStartTime = null;

                    if ($rules && isset($rules['weekly']['days'][$dayNameStr])) {
                        $dayConfig = $rules['weekly']['days'][$dayNameStr];
                        $isWorkingDay = $dayConfig['is_working'] ?? false;
                        if ($isWorkingDay) {
                            $expectedStartTime = $dayConfig['start'] ?? null;
                        }
                    }

                    $bgColor = '#f3f4f6';
                    $borderColor = '#e5e7eb';
                    $textColor = '#6b7280';
                    $statusText = '';

                    if ($dateObj->isFuture()) {
                        // Keep default
                    } elseif (!$isWorkingDay) {
                        $bgColor = '#e5e7eb';
                        $textColor = '#9ca3af';
                        $statusText = 'Off';
                    } else {
                        if ($dayLogs->isEmpty()) {
                            $bgColor = '#fee2e2';
                            $borderColor = '#fca5a5';
                            $textColor = '#b91c1c';
                            $statusText = 'Absent';
                        } else {
                            $isLate = false;
                            if ($expectedStartTime) {
                                // Default grace period of 15 minutes if not specified
                                $gracePeriod = $rules['grace_period'] ?? 15;
                                $expectedCarbon = \Carbon\Carbon::parse($dateString . ' ' . $expectedStartTime);
                                if ($firstPunch->punched_at->gt($expectedCarbon->addMinutes($gracePeriod))) {
                                    $isLate = true;
                                }
                            }

                            if ($isLate) {
                                $bgColor = '#fef3c7';
                                $borderColor = '#fcd34d';
                                $textColor = '#b45309';
                                $statusText = 'Late/Half';
                            } else {
                                $bgColor = '#dcfce7';
                                $borderColor = '#86efac';
                                $textColor = '#15803d';
                                $statusText = 'Present';
                            }
                        }
                    }
                    
                    $shiftLabel = $matchedSchedule ? $matchedSchedule->name : 'No Shift';
                    if ($matchedSchedule && $expectedStartTime) {
                        $endTime = $rules['weekly']['days'][$dayNameStr]['end'] ?? '';
                        $shiftLabel .= ' (' . $expectedStartTime . ' - ' . $endTime . ')';
                    }
                    
                    $logsJs = $dayLogs->sortBy('punched_at')->map(function($l) {
                        return [
                            'time' => $l->punched_at->format('h:i A'),
                            'status' => $l->status_label,
                            'verify' => $l->verify_type_label,
                        ];
                    })->values()->toJson();
                @endphp

                <td @click="if({{ $dayLogs->count() }} > 0 || '{{ $statusText }}' !== '') { selectedDate = '{{ $dateObj->format('M d, Y') }}'; selectedShift = '{{ $shiftLabel }}'; selectedLogs = {{ $logsJs }}; $dispatch('open-modal', { id: 'attendance-log-modal' }); }" 
                    style="padding: 0.5rem; border-radius: 0.5rem; border: 1px solid {{ $borderColor }}; background-color: {{ $bgColor }}; text-align: center; vertical-align: middle; height: 5rem; cursor: pointer; transition: all 0.2s;"
                    onmouseover="this.style.filter='brightness(0.95)'"
                    onmouseout="this.style.filter='brightness(1)'">
                    <div style="font-weight: 700; color: {{ $textColor }};">{{ $day }}</div>
                    @if($statusText)
                        <div style="font-size: 0.75rem; color: {{ $textColor }}; margin-top: 0.25rem; font-weight: 600;">{{ $statusText }}</div>
                    @endif
                    @if($dayLogs->isNotEmpty())
                        <div style="font-size: 0.65rem; color: #6b7280; margin-top: 0.25rem;" title="Matched Shift: {{ $shiftLabel }}">
                            {{ $firstPunch->punched_at->format('h:i A') }}
                        </div>
                    @endif
                </td>

                @php $currentDayOfWeek++; @endphp
                @if ($currentDayOfWeek === 7 && $day !== $daysInMonth)
                    </tr><tr>
                    @php $currentDayOfWeek = 0; @endphp
                @endif
            @endfor

            @for ($i = $currentDayOfWeek; $i < 7; $i++)
                <td style="padding: 0.5rem; border-radius: 0.5rem; border: 1px solid #e5e7eb; background-color: #f9fafb; opacity: 0.5;"></td>
            @endfor
            </tr>
        </tbody>
    </table>

    <div style="margin-top: 1.5rem; display: flex; gap: 1.5rem; justify-content: center; font-size: 0.875rem; color: #4b5563;">
        <div style="display: flex; align-items: center; gap: 0.5rem;"><span style="width: 0.75rem; height: 0.75rem; border-radius: 9999px; background-color: #22c55e;"></span> Present</div>
        <div style="display: flex; align-items: center; gap: 0.5rem;"><span style="width: 0.75rem; height: 0.75rem; border-radius: 9999px; background-color: #eab308;"></span> Late / Half Day</div>
        <div style="display: flex; align-items: center; gap: 0.5rem;"><span style="width: 0.75rem; height: 0.75rem; border-radius: 9999px; background-color: #ef4444;"></span> Absent</div>
        <div style="display: flex; align-items: center; gap: 0.5rem;"><span style="width: 0.75rem; height: 0.75rem; border-radius: 9999px; background-color: #d1d5db;"></span> Off / Holiday</div>
    </div>

    <!-- Filament Modal for Punch Details -->
    <x-filament::modal id="attendance-log-modal" width="xl" alignment="center">
        <x-slot name="heading">
            Logs for <span x-text="selectedDate"></span>
        </x-slot>
        <x-slot name="description">
            <span x-text="selectedShift"></span>
        </x-slot>

        <div style="margin-top: 1rem; overflow-x: auto; border: 1px solid var(--fi-color-gray-200); border-radius: 0.5rem;" class="dark:border-white/10">
            <table style="min-width: 100%; border-collapse: collapse; text-align: left;">
                <thead style="background-color: var(--fi-color-gray-50);" class="dark:bg-white/5">
                    <tr>
                        <th style="padding: 0.75rem 1rem; font-size: 0.75rem; font-weight: 600; color: var(--fi-color-gray-500); text-transform: uppercase; border-bottom: 1px solid var(--fi-color-gray-200);" class="dark:text-gray-400 dark:border-white/10">Time</th>
                        <th style="padding: 0.75rem 1rem; font-size: 0.75rem; font-weight: 600; color: var(--fi-color-gray-500); text-transform: uppercase; border-bottom: 1px solid var(--fi-color-gray-200);" class="dark:text-gray-400 dark:border-white/10">Status</th>
                        <th style="padding: 0.75rem 1rem; font-size: 0.75rem; font-weight: 600; color: var(--fi-color-gray-500); text-transform: uppercase; border-bottom: 1px solid var(--fi-color-gray-200);" class="dark:text-gray-400 dark:border-white/10">Verified By</th>
                    </tr>
                </thead>
                <tbody style="background-color: var(--fi-color-white);" class="dark:bg-gray-900">
                    <template x-if="selectedLogs.length === 0">
                        <tr>
                            <td colspan="3" style="padding: 1rem; text-align: center; font-size: 0.875rem; color: var(--fi-color-gray-500); border-bottom: 1px solid var(--fi-color-gray-200);" class="dark:text-gray-400 dark:border-white/10">No logs found for this day.</td>
                        </tr>
                    </template>
                    <template x-for="log in selectedLogs" :key="log.time">
                        <tr>
                            <td style="padding: 1rem; font-size: 0.875rem; font-weight: 500; color: var(--fi-color-gray-900); border-bottom: 1px solid var(--fi-color-gray-200);" class="dark:text-white dark:border-white/10" x-text="log.time"></td>
                            <td style="padding: 1rem; font-size: 0.875rem; color: var(--fi-color-gray-500); border-bottom: 1px solid var(--fi-color-gray-200);" class="dark:border-white/10">
                                <span style="padding: 0.125rem 0.5rem; display: inline-flex; font-size: 0.75rem; font-weight: 600; border-radius: 9999px; background-color: rgba(var(--fi-color-primary-500), 0.1); color: var(--fi-color-primary-600);" class="dark:text-primary-400" x-text="log.status"></span>
                            </td>
                            <td style="padding: 1rem; font-size: 0.875rem; color: var(--fi-color-gray-500); border-bottom: 1px solid var(--fi-color-gray-200);" class="dark:text-gray-400 dark:border-white/10" x-text="log.verify"></td>
                        </tr>
                    </template>
                </tbody>
            </table>
        </div>

        <x-slot name="footerActions">
            <x-filament::button color="gray" x-on:click="close()">
                Close
            </x-filament::button>
        </x-slot>
    </x-filament::modal>
</div>
