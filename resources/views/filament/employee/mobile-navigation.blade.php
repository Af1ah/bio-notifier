@php
    use App\Filament\Employee\Pages\{Attendance, Calendar, Dashboard, SalarySlips};
    use Filament\Support\Icons\Heroicon;
@endphp

@if (($employee = auth('employee')->user()) && $employee->canAccessEmployeePortal() && ! $employee->requires_password_change)
    <nav class="portal-mobile-nav" aria-label="{{ __('portal-ui.navigation') }}">
        @foreach ([
            [Dashboard::class, 'home', Heroicon::OutlinedSquares2x2],
            [Attendance::class, 'attendance', Heroicon::OutlinedClock],
            [Calendar::class, 'calendar', Heroicon::OutlinedCalendarDays],
            [SalarySlips::class, 'payslips', Heroicon::OutlinedBanknotes],
        ] as [$page, $label, $icon])
            <x-portal-ui.button :href="$page::getUrl(panel: 'employee')" :icon="$icon" variant="nav"
                :active="request()->routeIs($page::getRouteName())" wire:navigate>
                {{ __('portal-ui.'.$label) }}
            </x-portal-ui.button>
        @endforeach
    </nav>
@endif
