<?php

namespace App\Livewire\Tenant;

use App\Models\User;
use App\Services\Support\TenantTimezone;
use Carbon\Carbon;
use Filament\Facades\Filament;
use Livewire\Attributes\Locked;
use Livewire\Component;

class UserAttendanceCalendar extends Component
{
    #[Locked]
    public User $record;

    public $year;

    public $month;

    public function mount(User $record)
    {
        $this->authorizeRecord($record);
        $this->record = $record;
        $this->year = now(TenantTimezone::get())->year;
        $this->month = now(TenantTimezone::get())->month;
    }

    public function previousMonth()
    {
        $date = Carbon::create($this->year, $this->month, 1)->subMonth();
        $this->year = $date->year;
        $this->month = $date->month;
    }

    public function nextMonth()
    {
        $date = Carbon::create($this->year, $this->month, 1)->addMonth();
        $this->year = $date->year;
        $this->month = $date->month;
    }

    public function render()
    {
        $this->authorizeRecord($this->record);

        return view('livewire.tenant.user-attendance-calendar');
    }

    private function authorizeRecord(User $record): void
    {
        $guard = Filament::getCurrentPanel()?->getId() === 'employee' ? 'employee' : 'web';
        $actor = auth($guard)->user()?->fresh();
        abort_unless($actor && ($actor->hasManagementAccess() || ($actor->canAccessEmployeePortal() && ! $actor->requires_password_change && $actor->id === $record->id)), 403);
    }
}
