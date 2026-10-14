<?php

namespace App\Filament\Tenant\Resources\EmployeeLeaves\Pages;

use App\Filament\Tenant\LeaveActionErrors;
use App\Filament\Tenant\Resources\EmployeeLeaves\EmployeeLeaveResource;
use App\Services\Leave\EmployeeLeaveService;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Database\Eloquent\Model;

class CreateEmployeeLeave extends CreateRecord
{
    protected static string $resource = EmployeeLeaveResource::class;

    protected function handleRecordCreation(array $data): Model
    {
        return LeaveActionErrors::form(fn () => app(EmployeeLeaveService::class)->save(null, $data, auth()->user()));
    }
}
