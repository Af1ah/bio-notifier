<?php

namespace App\Filament\Master\Resources\Organisations\Pages;

use App\Filament\Master\Resources\Organisations\OrganisationResource;
use Filament\Actions\DeleteAction;
use Filament\Resources\Pages\EditRecord;

class EditOrganisation extends EditRecord
{
    protected static string $resource = OrganisationResource::class;

    protected function getHeaderActions(): array
    {
        return [
            DeleteAction::make(),
        ];
    }
}
