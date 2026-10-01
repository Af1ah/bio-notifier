<?php

namespace App\Filament\Tenant\Resources\EmployeeLeaves\Pages;

use App\Filament\Tenant\Resources\EmployeeLeaves\EmployeeLeaveResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListEmployeeLeaves extends ListRecords
{
    protected static string $resource = EmployeeLeaveResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make(),
        ];
    }
}
