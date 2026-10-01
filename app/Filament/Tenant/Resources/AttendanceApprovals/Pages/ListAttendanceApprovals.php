<?php

namespace App\Filament\Tenant\Resources\AttendanceApprovals\Pages;

use App\Filament\Tenant\Resources\AttendanceApprovals\AttendanceApprovalResource;
use App\Models\AttendanceApproval;
use Filament\Resources\Pages\ListRecords;
use Filament\Schemas\Components\Tabs\Tab;
use Illuminate\Database\Eloquent\Builder;

class ListAttendanceApprovals extends ListRecords
{
    protected static string $resource = AttendanceApprovalResource::class;

    public function getTabs(): array
    {
        return [
            'attendance' => Tab::make('Attendance approvals')->badge(fn () => AttendanceApproval::where('type', 'attendance')->where('state', 'pending')->count())->modifyQueryUsing(fn (Builder $query) => $query->where('type', 'attendance')),
            'overtime' => Tab::make('OT approvals')->badge(fn () => AttendanceApproval::where('type', 'overtime')->where('state', 'pending')->count())->modifyQueryUsing(fn (Builder $query) => $query->where('type', 'overtime')),
        ];
    }

    public function getDefaultActiveTab(): string|int|null
    {
        return 'attendance';
    }

    public function updatedActiveTab(): void
    {
        $this->deselectAllTableRecords();
    }
}
