<x-filament-panels::page>
    @livewire(\App\Livewire\Tenant\UserAttendanceCalendar::class, ['record' => \App\Services\EmployeePortal\PortalAccess::employee()])
</x-filament-panels::page>
