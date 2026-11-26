<?php

namespace App\Filament\Employee\Widgets;

use App\Models\AttendanceDay;
use App\Models\EmployeePortalSetting;
use App\Models\PortalPunch;
use App\Models\WorkLocation;
use App\Services\Attendance\AttendanceDayDisplay;
use App\Services\EmployeePortal\GeofencedPunchService;
use App\Services\EmployeePortal\PortalAccess;
use App\Services\Support\TenantTimezone;
use Filament\Widgets\Widget;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Locked;

class LocationPunch extends Widget
{
    #[Locked]
    public bool $geofenceEnabled = true;

    protected string $view = 'filament.employee.widgets.location-punch';

    protected int|string|array $columnSpan = 'full';

    protected static bool $isLazy = false;

    public function boot(): void
    {
        PortalAccess::employee();
        $this->geofenceEnabled = EmployeePortalSetting::geofencingEnabled();
    }

    protected function getViewData(): array
    {
        $employee = PortalAccess::employee();
        $timezone = TenantTimezone::get();
        $today = now($timezone);
        $day = AttendanceDay::with(['occurrences', 'approvals'])->where('user_id', $employee->id)
            ->whereDate('work_date', $today)->first();
        $occurrences = $day?->occurrences;
        $firstIn = $occurrences?->pluck('first_in_at')->filter()->sort()->first();
        $lastOut = $occurrences?->pluck('last_out_at')->filter()->sortDesc()->first();

        return [
            'employee' => $employee,
            'locations' => $this->geofenceEnabled
                ? WorkLocation::where('is_active', true)->orderBy('name')->pluck('name', 'id')
                : collect(),
            'today' => $today,
            'timezone' => $timezone,
            'firstIn' => $firstIn?->format('h:i A') ?? '—',
            'lastOut' => $lastOut?->format('h:i A') ?? '—',
            'logged' => AttendanceDayDisplay::forDay($day)['clock'].'h',
        ];
    }

    public function lastPunch(): ?PortalPunch
    {
        return PortalPunch::where('user_id', PortalAccess::employee()->id)->latest('id')->first();
    }

    public function punch(array $data): bool
    {
        try {
            $log = app(GeofencedPunchService::class)->record($data);
        } catch (ValidationException $exception) {
            $this->dispatch('portal-punch-rejected', message: collect($exception->errors())->flatten()->first());

            return false;
        }
        $this->dispatch('portal-punch-submitted');
        $this->dispatch('portal-punch-receipt', kind: $log->kind, time: $log->punched_at->format('d M, h:i A'), state: $log->state);

        return true;
    }
}
