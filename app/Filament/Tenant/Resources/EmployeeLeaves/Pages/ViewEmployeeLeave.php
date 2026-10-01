<?php

namespace App\Filament\Tenant\Resources\EmployeeLeaves\Pages;

use App\Filament\Tenant\Resources\EmployeeLeaves\EmployeeLeaveResource;
use Filament\Actions\EditAction;
use Filament\Resources\Pages\ViewRecord;

class ViewEmployeeLeave extends ViewRecord
{
    protected static string $resource = EmployeeLeaveResource::class;

    protected function getHeaderActions(): array
    {
        return [
            EditAction::make(),
        ];
    }
}
