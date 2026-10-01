<?php

namespace App\Filament\Tenant\Resources\EmployeeLeaves\Pages;

use App\Filament\Tenant\Resources\EmployeeLeaves\EmployeeLeaveResource;
use Filament\Resources\Pages\CreateRecord;

class CreateEmployeeLeave extends CreateRecord
{
    protected static string $resource = EmployeeLeaveResource::class;
}
