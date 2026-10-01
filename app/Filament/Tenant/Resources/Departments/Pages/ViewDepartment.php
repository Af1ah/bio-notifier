<?php

namespace App\Filament\Tenant\Resources\Departments\Pages;

use App\Filament\Tenant\Actions\AssignShiftAction;
use App\Filament\Tenant\Actions\EndShiftAssignmentAction;
use App\Filament\Tenant\Resources\Departments\DepartmentResource;
use Filament\Actions\EditAction;
use Filament\Resources\Pages\ViewRecord;
use Illuminate\Contracts\Support\Htmlable;

class ViewDepartment extends ViewRecord
{
    protected static string $resource = DepartmentResource::class;

    protected function getHeaderActions(): array
    {
        return [
            AssignShiftAction::make('department'),
            EndShiftAssignmentAction::make('department'),
            EditAction::make(),
        ];
    }

    public function getTitle(): string|Htmlable
    {
        return $this->record->name ?? 'View Department';
    }

    protected function hasInfolist(): bool
    {
        return true;
    }
}
