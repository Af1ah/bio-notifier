<x-dynamic-component
    :component="$getFieldWrapperView()"
    :field="$field"
>
    <div x-data="weeklySchedule({ state: $wire.$entangle('{{ $getStatePath() }}') })" style="display: flex; flex-direction: column; gap: 1rem;">
        
        <!-- Table -->
        <div class="fi-ta-ctn overflow-hidden rounded-xl bg-white shadow-sm ring-1 ring-gray-950/5 dark:bg-gray-900 dark:ring-white/10">
            <div class="fi-ta-content overflow-x-auto">
                <table class="fi-ta-table w-full text-left" style="width: 100%; border-collapse: collapse;">
                    <thead class="bg-gray-50 dark:bg-white/5">
                        <tr>
                            <!-- Day column -->
                            <th class="fi-ta-header-cell" style="padding: 0.875rem 1.5rem; text-align: left; border-bottom: 1px solid rgba(156,163,175,0.2); min-width: 9rem;">
                                <span class="text-sm font-semibold text-gray-950 dark:text-white">Day</span>
                            </th>

                            <!-- Opening Time — header is the default input -->
                            <th class="fi-ta-header-cell" style="padding: 0.875rem 1rem; text-align: left; border-bottom: 1px solid rgba(156,163,175,0.2); min-width: 11rem;">
                                <div style="display: flex; flex-direction: column; gap: 0.25rem;">
                                    <span class="text-xs font-medium text-gray-500 dark:text-gray-400" style="text-transform: uppercase; letter-spacing: 0.05em;">Opening Time</span>
                                    <div style="display: flex; align-items: center; gap: 0.4rem;">
                                        <x-filament::input.wrapper>
                                            <x-filament::input type="time" x-model="defaults.start" @change="applyDefaults()" title="Set default opening time for all days" style="font-size: 0.8rem;" />
                                        </x-filament::input.wrapper>
                                        <span title="Applies to all working days" style="color: #9ca3af; cursor: default; font-size: 0.75rem;">✦</span>
                                    </div>
                                </div>
                            </th>

                            <!-- Dynamic break columns -->
                            <template x-for="brk in Object.values(breaks)" :key="brk.id">
                                <th class="fi-ta-header-cell" style="padding: 0.875rem 1rem; text-align: left; border-bottom: 1px solid rgba(156,163,175,0.2); min-width: 10rem;">
                                    <div style="display: flex; flex-direction: column; gap: 0.25rem;">
                                        <div style="display: flex; align-items: center; gap: 0.4rem;">
                                            <span class="text-xs font-semibold text-gray-950 dark:text-white" x-text="brk.name"></span>
                                            <button type="button" @click="removeBreak(brk.id)" style="color: #ef4444; line-height: 1;" title="Remove break">
                                                <x-filament::icon icon="heroicon-m-x-mark" style="width: 0.85rem; height: 0.85rem;"/>
                                            </button>
                                        </div>
                                        <span class="text-xs text-gray-500 dark:text-gray-400 hover:text-primary-600 transition-colors" 
                                              style="cursor: pointer; display: inline-flex; align-items: center; gap: 0.25rem;"
                                              @click="$wire.mountAction('editBreak', { id: brk.id }, { schemaComponent: '{{ $field->getKey() }}' })" 
                                              title="Edit Default Break">
                                            <span>
                                                <span x-text="brk.start"></span> • <span x-text="brk.duration"></span>&thinsp;<span x-text="brk.duration_unit"></span>
                                            </span>
                                            <x-filament::icon icon="heroicon-m-pencil-square" style="width: 0.85rem; height: 0.85rem;"/>
                                        </span>
                                    </div>
                                </th>
                            </template>

                            <!-- Closing Time — header is the default input -->
                            <th class="fi-ta-header-cell" style="padding: 0.875rem 1rem; text-align: left; border-bottom: 1px solid rgba(156,163,175,0.2); min-width: 11rem;">
                                <div style="display: flex; flex-direction: column; gap: 0.25rem;">
                                    <span class="text-xs font-medium text-gray-500 dark:text-gray-400" style="text-transform: uppercase; letter-spacing: 0.05em;">Closing Time</span>
                                    <div style="display: flex; align-items: center; gap: 0.4rem;">
                                        <x-filament::input.wrapper>
                                            <x-filament::input type="time" x-model="defaults.end" @change="applyDefaults()" title="Set default closing time for all days" style="font-size: 0.8rem;" />
                                        </x-filament::input.wrapper>
                                        <span title="Applies to all working days" style="color: #9ca3af; cursor: default; font-size: 0.75rem;">✦</span>
                                    </div>
                                </div>
                            </th>

                            <!-- Total Hours -->
                            <th class="fi-ta-header-cell" style="padding: 0.875rem 1.5rem; text-align: left; border-bottom: 1px solid rgba(156,163,175,0.2); min-width: 8rem;">
                                <span class="text-sm font-semibold text-gray-950 dark:text-white">Total Hours</span>
                            </th>
                        </tr>
                    </thead>
                    <tbody>
                        <template x-for="dayKey in ['monday', 'tuesday', 'wednesday', 'thursday', 'friday', 'saturday', 'sunday']" :key="dayKey">
                            <tr class="fi-ta-row transition duration-75 hover:bg-gray-50 dark:hover:bg-white/5" style="border-bottom: 1px solid rgba(156,163,175,0.08);">
                                <!-- Day toggle + name -->
                                <td class="fi-ta-cell" style="padding: 0.875rem 1.5rem; vertical-align: middle;">
                                    <div style="display: flex; align-items: center; gap: 0.75rem;">
                                        <x-filament::input.checkbox x-model="days[dayKey].is_working" @change="updateState()" />
                                        <span class="text-sm font-medium text-gray-950 dark:text-white capitalize" x-text="dayKey"></span>
                                    </div>
                                </td>

                                <!-- Opening time -->
                                <td class="fi-ta-cell" style="padding: 0.875rem 1rem; vertical-align: middle;">
                                    <div x-show="days[dayKey].is_working">
                                        <x-filament::input.wrapper>
                                            <x-filament::input type="time" x-model="days[dayKey].start" @change="updateState()" />
                                        </x-filament::input.wrapper>
                                    </div>
                                </td>

                                <!-- Break cells -->
                                <template x-for="brk in Object.values(breaks)" :key="brk.id">
                                    <td class="fi-ta-cell" style="padding: 0.875rem 1rem; vertical-align: middle;">
                                        <div style="display: flex; align-items: center; gap: 0.5rem;" x-show="days[dayKey].is_working">
                                            <x-filament::input.checkbox x-model="days[dayKey].breaks[brk.id].is_active" @change="updateState()" title="Enable this break for the day" />
                                            <div x-show="days[dayKey].breaks[brk.id].is_active" style="flex: 1; display: flex; flex-direction: column; gap: 0.25rem; cursor: pointer;" 
                                                 @click="$wire.mountAction('editDayBreak', { id: brk.id, day: dayKey }, { schemaComponent: '{{ $field->getKey() }}' })" 
                                                 class="group"
                                                 title="Edit Special Time & Duration">
                                                <div style="display: flex; align-items: center; gap: 0.25rem;">
                                                    <span class="text-sm font-medium text-gray-950 dark:text-white group-hover:text-primary-600 transition-colors" x-text="days[dayKey].breaks[brk.id].start"></span>
                                                    <x-filament::icon icon="heroicon-m-pencil" class="text-gray-400 group-hover:text-primary-600 transition-colors" style="width: 0.85rem; height: 0.85rem;" />
                                                </div>
                                                <span class="text-xs text-gray-500" x-text="days[dayKey].breaks[brk.id].duration + ' ' + days[dayKey].breaks[brk.id].duration_unit"></span>
                                            </div>
                                        </div>
                                    </td>
                                </template>

                                <!-- Closing time -->
                                <td class="fi-ta-cell" style="padding: 0.875rem 1rem; vertical-align: middle;">
                                    <div x-show="days[dayKey].is_working">
                                        <x-filament::input.wrapper>
                                            <x-filament::input type="time" x-model="days[dayKey].end" @change="updateState()" />
                                        </x-filament::input.wrapper>
                                    </div>
                                </td>

                                <!-- Total hours -->
                                <td class="fi-ta-cell" style="padding: 0.875rem 1.5rem; vertical-align: middle;">
                                    <span class="text-sm font-semibold text-gray-950 dark:text-white" x-show="days[dayKey].is_working" x-text="calculateTotalHours(dayKey)"></span>
                                    <span class="text-sm text-gray-400 dark:text-gray-500" x-show="!days[dayKey].is_working">Off</span>
                                </td>
                            </tr>
                        </template>
                    </tbody>
                </table>
            </div>
        </div>

    </div>

    <script>
        document.addEventListener('alpine:init', () => {
            Alpine.data('weeklySchedule', ({ state }) => ({
                state: state,
                defaults: { start: '09:00', end: '18:00' },
                breaks: [],
                days: {
                    monday: { is_working: true, start: '09:00', end: '18:00', breaks: {} },
                    tuesday: { is_working: true, start: '09:00', end: '18:00', breaks: {} },
                    wednesday: { is_working: true, start: '09:00', end: '18:00', breaks: {} },
                    thursday: { is_working: true, start: '09:00', end: '18:00', breaks: {} },
                    friday: { is_working: true, start: '09:00', end: '18:00', breaks: {} },
                    saturday: { is_working: false, start: '09:00', end: '18:00', breaks: {} },
                    sunday: { is_working: false, start: '09:00', end: '18:00', breaks: {} },
                },

                // Flag to prevent circular reactivity:
                // updateState() sets this.state → $watch('state') → loadFromState() → sets this.breaks → $watch('breaks') → loop
                _syncingToWire: false,

                init() {
                    const loadFromState = (s) => {
                        if (s && typeof s === 'object' && !Array.isArray(s)) {
                            if (s.defaults) this.defaults = { ...this.defaults, ...s.defaults };
                            if (s.breaks) {
                                // Ensure breaks is stored as an object internally to easily lookup
                                let parsedBreaks = JSON.parse(JSON.stringify(s.breaks));
                                if (Array.isArray(parsedBreaks)) {
                                    let obj = {};
                                    parsedBreaks.forEach(b => { if (b && b.id) obj[b.id] = b; });
                                    this.breaks = obj;
                                } else {
                                    this.breaks = parsedBreaks;
                                }
                            }
                            if (s.days) {
                                for (const day in s.days) {
                                    if (this.days[day]) {
                                        let sourceDay = s.days[day];
                                        this.days[day].is_working = sourceDay.is_working;
                                        this.days[day].start = sourceDay.start;
                                        this.days[day].end = sourceDay.end;
                                        
                                        let breaksObj = {};
                                        if (Array.isArray(sourceDay.breaks)) {
                                            sourceDay.breaks.forEach(b => {
                                                if (b && b.id) breaksObj[b.id] = b;
                                            });
                                        } else if (sourceDay.breaks && typeof sourceDay.breaks === 'object') {
                                            breaksObj = sourceDay.breaks;
                                        }
                                        
                                        for (const brkId in this.breaks) {
                                            if (!this.days[day].breaks[brkId]) {
                                                this.days[day].breaks[brkId] = {};
                                            }
                                            
                                            if (breaksObj[brkId]) {
                                                this.days[day].breaks[brkId].id = breaksObj[brkId].id;
                                                this.days[day].breaks[brkId].is_active = breaksObj[brkId].is_active;
                                                this.days[day].breaks[brkId].start = breaksObj[brkId].start;
                                                this.days[day].breaks[brkId].duration = breaksObj[brkId].duration;
                                                this.days[day].breaks[brkId].duration_unit = breaksObj[brkId].duration_unit;
                                            } else {
                                                this.days[day].breaks[brkId].id = brkId;
                                                this.days[day].breaks[brkId].is_active = false;
                                                this.days[day].breaks[brkId].start = this.breaks[brkId].start;
                                                this.days[day].breaks[brkId].duration = this.breaks[brkId].duration;
                                                this.days[day].breaks[brkId].duration_unit = this.breaks[brkId].duration_unit;
                                            }
                                        }
                                        
                                        for (const brkId in this.days[day].breaks) {
                                            if (!this.breaks[brkId]) {
                                                delete this.days[day].breaks[brkId];
                                            }
                                        }
                                    }
                                }
                            }
                        } else {
                            this.updateState();
                        }
                    };

                    loadFromState(this.state);

                    // Only re-sync when the update came FROM the server (Filament Action / Livewire),
                    // not when Alpine itself called updateState(). The _syncingToWire flag guards this.
                    this.$watch('state', (newState) => {
                        if (this._syncingToWire) return;
                        loadFromState(newState);
                    });
                    // NOTE: Do NOT add $watch('breaks') or $watch('defaults') — those create circular loops.
                    //       All user-triggered changes call updateState() explicitly instead.
                },
                
                updateState() {
                    this._syncingToWire = true;
                    this.state = {
                        defaults: this.defaults,
                        breaks: Object.values(this.breaks),
                        days: this.days
                    };
                    // Reset after the $watch fires and returns
                    this.$nextTick(() => { this._syncingToWire = false; });
                },

                applyDefaults() {
                    const daysList = ['monday', 'tuesday', 'wednesday', 'thursday', 'friday', 'saturday', 'sunday'];
                    daysList.forEach(day => {
                        if (this.days[day].is_working) {
                            this.days[day].start = this.defaults.start;
                            this.days[day].end = this.defaults.end;
                        }
                    });
                    this.updateState();
                },
                

                removeBreak(id) {
                    if (Array.isArray(this.breaks)) {
                        this.breaks = this.breaks.filter(b => b.id !== id);
                    } else {
                        delete this.breaks[id];
                    }
                    const daysList = ['monday', 'tuesday', 'wednesday', 'thursday', 'friday', 'saturday', 'sunday'];
                    daysList.forEach(day => {
                        if (this.days[day].breaks && this.days[day].breaks[id]) {
                            delete this.days[day].breaks[id];
                        }
                    });
                    this.updateState();
                },
                
                calculateTotalHours(dayKey) {
                    const day = this.days[dayKey];
                    if (!day.is_working || !day.start || !day.end) return '0h 0m';
                    
                    let startParts = day.start.split(':').map(Number);
                    let endParts = day.end.split(':').map(Number);
                    
                    let startMins = startParts[0] * 60 + startParts[1];
                    let endMins = endParts[0] * 60 + endParts[1];
                    
                    if (endMins < startMins) endMins += 24 * 60; // overnight
                    
                    let totalMins = endMins - startMins;
                    
                    if (day.breaks) {
                        for (const brkId in day.breaks) {
                            const brk = day.breaks[brkId];
                            if (brk.is_active) {
                                let dur = parseInt(brk.duration) || 0;
                                if (brk.duration_unit === 'hours') dur *= 60;
                                totalMins -= dur;
                            }
                        }
                    }
                    
                    if (totalMins < 0) totalMins = 0;
                    if (isNaN(totalMins)) return '0h 0m';
                    
                    const hours = Math.floor(totalMins / 60);
                    const mins = totalMins % 60;
                    
                    return `${hours}h ${mins}m`;
                }
            }));
        });
    </script>
</x-dynamic-component>
