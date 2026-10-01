<?php

namespace App\Filament\Tenant\Resources\LeaveTypes\Pages;

use App\Filament\Tenant\Resources\LeaveTypes\LeaveTypeResource;
use Filament\Resources\Pages\CreateRecord;

class CreateLeaveType extends CreateRecord
{
    protected static string $resource = LeaveTypeResource::class;
}
