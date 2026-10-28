<?php

namespace App\Filament\Master\Pages;

use App\Filament\Master\Resources\Organisations\OrganisationResource;

final class Dashboard extends \Filament\Pages\Dashboard
{
    protected static bool $shouldRegisterNavigation = false;

    public function mount(): void
    {
        $this->redirect(OrganisationResource::getUrl('index'));
    }
}
