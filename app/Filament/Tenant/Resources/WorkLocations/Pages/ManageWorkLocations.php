<?php

namespace App\Filament\Tenant\Resources\WorkLocations\Pages;

use App\Filament\Tenant\Resources\WorkLocations\WorkLocationResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ManageRecords;

class ManageWorkLocations extends ManageRecords
{
    protected static string $resource = WorkLocationResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make()->modalWidth('3xl'),
        ];
    }
}
