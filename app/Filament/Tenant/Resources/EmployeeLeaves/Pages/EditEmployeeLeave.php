<?php

namespace App\Filament\Tenant\Resources\EmployeeLeaves\Pages;

use App\Filament\Tenant\Resources\EmployeeLeaves\EmployeeLeaveResource;
use Filament\Actions\DeleteAction;
use Filament\Actions\ViewAction;
use Filament\Resources\Pages\EditRecord;

class EditEmployeeLeave extends EditRecord
{
    protected static string $resource = EmployeeLeaveResource::class;

    protected function getHeaderActions(): array
    {
        return [
            ViewAction::make(),
            DeleteAction::make(),
        ];
    }
}
