<?php

namespace App\Filament\Tenant\Resources\LeaveTypes\Pages;

use App\Filament\Tenant\Resources\LeavePolicies\LeavePolicyResource;
use App\Filament\Tenant\Resources\LeaveTypes\LeaveTypeResource;
use Filament\Actions\Action;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListLeaveTypes extends ListRecords
{
    protected static string $resource = LeaveTypeResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Action::make('settings')->label('Leave settings')->url(LeavePolicyResource::getUrl()),
            CreateAction::make(),
        ];
    }
}
