<?php

namespace App\Filament\Employee\Pages;

use App\Services\EmployeePortal\PortalAccess;
use Filament\Pages\Page;

abstract class PortalPage extends Page
{
    public function boot(): void
    {
        PortalAccess::employee();
    }
}
