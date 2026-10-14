<?php

namespace App\Filament\Tenant\Resources\EmployeeLeaves\Pages;

use App\Filament\Tenant\LeaveActionErrors;
use App\Filament\Tenant\Resources\EmployeeLeaves\EmployeeLeaveResource;
use App\Services\Leave\EmployeeLeaveService;
use Filament\Actions\ViewAction;
use Filament\Resources\Pages\EditRecord;
use Illuminate\Database\Eloquent\Model;

class EditEmployeeLeave extends EditRecord
{
    protected static string $resource = EmployeeLeaveResource::class;

    protected function handleRecordUpdate(Model $record, array $data): Model
    {
        return LeaveActionErrors::form(fn () => app(EmployeeLeaveService::class)->save($record, $data, auth()->user()));
    }

    protected function getHeaderActions(): array
    {
        return [
            ViewAction::make(),

        ];
    }
}
