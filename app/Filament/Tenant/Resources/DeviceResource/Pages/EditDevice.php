<?php

namespace App\Filament\Tenant\Resources\DeviceResource\Pages;

use Filament\Actions;
use Filament\Resources\Pages\EditRecord;
use App\Filament\Tenant\Resources\DeviceResource;

class EditDevice extends EditRecord
{
    protected static string $resource = DeviceResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\ViewAction::make(),
            Actions\DeleteAction::make(),
        ];
    }
}
