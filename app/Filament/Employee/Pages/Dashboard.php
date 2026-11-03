<?php

namespace App\Filament\Employee\Pages;

use App\Filament\Employee\Widgets\PortalOverview;
use App\Services\EmployeePortal\PortalAccess;

class Dashboard extends \Filament\Pages\Dashboard
{
    public function boot(): void
    {
        PortalAccess::employee();
    }

    public function getWidgets(): array
    {
        return [\App\Filament\Employee\Widgets\LocationPunch::class, PortalOverview::class];
    }
}
