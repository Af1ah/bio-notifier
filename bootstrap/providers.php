<?php

use App\Providers\AppServiceProvider;
use App\Providers\Filament\EmployeePanelProvider;
use App\Providers\Filament\MasterPanelProvider;
use App\Providers\Filament\TenantPanelProvider;
use App\Providers\TenancyServiceProvider;

return [
    AppServiceProvider::class,
    EmployeePanelProvider::class,
    MasterPanelProvider::class,
    TenantPanelProvider::class,
    TenancyServiceProvider::class,
];
