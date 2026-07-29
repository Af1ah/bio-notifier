<?php

namespace App\Filament\Tenant\Resources\UserResource\Pages;

use Filament\Actions;
use Filament\Resources\Pages\ViewRecord;
use App\Filament\Tenant\Resources\UserResource;

class ViewUser extends ViewRecord
{
    protected static string $resource = UserResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\EditAction::make(),
        ];
    }
}
