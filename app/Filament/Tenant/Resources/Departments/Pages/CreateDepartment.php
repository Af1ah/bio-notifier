<?php

namespace App\Filament\Tenant\Resources\Departments\Pages;

use App\Filament\Tenant\Resources\Departments\DepartmentResource;
use Filament\Resources\Pages\CreateRecord;

class CreateDepartment extends CreateRecord
{
    protected static string $resource = DepartmentResource::class;
}
