<?php

namespace App\Filament\Employee\Pages;

use App\Filament\Employee\Widgets\LocationPunch;
use App\Filament\Employee\Widgets\PortalOverview;
use App\Services\EmployeePortal\PortalAccess;

class Dashboard extends \Filament\Pages\Dashboard
{
    public function boot(): void
    {
        PortalAccess::employee();
    }

    public function getHeading(): string
    {
        return '';
    }

    public function getWidgets(): array
    {
        return [LocationPunch::class, PortalOverview::class];
    }
}
