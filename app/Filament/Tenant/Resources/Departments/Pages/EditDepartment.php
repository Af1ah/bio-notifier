<?php

namespace App\Filament\Tenant\Resources\Departments\Pages;

use App\Filament\Tenant\Actions\AssignShiftAction;
use App\Filament\Tenant\Actions\EndShiftAssignmentAction;
use App\Filament\Tenant\Resources\Departments\DepartmentResource;
use Filament\Actions\DeleteAction;
use Filament\Resources\Pages\EditRecord;

class EditDepartment extends EditRecord
{
    protected static string $resource = DepartmentResource::class;

    protected function getHeaderActions(): array
    {
        return [
            AssignShiftAction::make('department'),
            EndShiftAssignmentAction::make('department'),
            DeleteAction::make(),
        ];
    }
}
