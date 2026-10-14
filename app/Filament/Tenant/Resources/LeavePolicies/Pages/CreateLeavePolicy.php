<?php

namespace App\Filament\Tenant\Resources\LeavePolicies\Pages;

use App\Filament\Tenant\LeaveActionErrors;
use App\Filament\Tenant\Resources\LeavePolicies\LeavePolicyResource;
use App\Services\Leave\LeavePolicyService;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Database\Eloquent\Model;

class CreateLeavePolicy extends CreateRecord
{
    protected static string $resource = LeavePolicyResource::class;

    protected function handleRecordCreation(array $data): Model
    {
        return LeaveActionErrors::form(fn () => app(LeavePolicyService::class)->save(null, $data, auth()->user()));
    }
}
