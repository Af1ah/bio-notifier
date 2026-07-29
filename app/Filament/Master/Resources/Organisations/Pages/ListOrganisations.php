<?php

namespace App\Filament\Master\Resources\Organisations\Pages;

use App\Filament\Master\Resources\Organisations\OrganisationResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListOrganisations extends ListRecords
{
    protected static string $resource = OrganisationResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make(),
        ];
    }
}
