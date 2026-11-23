<?php

namespace App\Livewire\Tenant;

use App\Models\LeaveCreditLot;
use App\Models\LeaveType;
use App\Models\User;
use App\Services\Leave\LeaveBalanceSummaryService;
use App\Services\Leave\LeaveSettingsService;
use Carbon\Carbon;
use Livewire\Attributes\Locked;
use Livewire\Component;

class EmployeeLeaveBalances extends Component
{
    #[Locked]
    public User $record;

    #[Locked]
    public ?int $historyType = null;

    public function showHistory(int $typeId): void
    {
        abort_unless((bool) auth()->user()?->hasManagementAccess(), 403);
        LeaveType::where('active', true)->where('is_paid', true)->findOrFail($typeId);
        $this->historyType = $typeId;
        $this->dispatch('open-modal', id: 'employee-leave-history-'.$this->record->id);
    }

    public function render()
    {
        abort_unless((bool) auth()->user()?->hasManagementAccess(), 403);
        $user = User::employees()->findOrFail($this->record->id);
        $date = Carbon::today(LeaveSettingsService::timezone());
        $balances = LeaveType::where('active', true)->where('is_paid', true)->orderBy('name')->get()->map(fn ($type) => [
            'type' => $type, 'balance' => app(LeaveBalanceSummaryService::class)->get($user, $type->id, $date),
        ]);
        $lots = $this->historyType ? LeaveCreditLot::with('entries')->where('user_id', $user->id)->where('leave_type_id', $this->historyType)->orderByDesc('id')->get() : collect();

        return view('livewire.tenant.employee-leave-balances', compact('balances', 'lots'));
    }
}
