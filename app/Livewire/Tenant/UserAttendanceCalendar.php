<?php

namespace App\Livewire\Tenant;

use Livewire\Component;
use App\Models\User;
use Carbon\Carbon;

class UserAttendanceCalendar extends Component
{
    public User $record;
    public $year;
    public $month;

    public function mount(User $record)
    {
        $this->record = $record;
        $this->year = now()->year;
        $this->month = now()->month;
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
        return view('livewire.tenant.user-attendance-calendar');
    }
}
