<?php

namespace App\Filament\Master\Resources\Organisations\Pages;

use App\Filament\Master\Resources\Organisations\OrganisationResource;
use Filament\Resources\Pages\CreateRecord;

class CreateOrganisation extends CreateRecord
{
    protected static string $resource = OrganisationResource::class;
}
