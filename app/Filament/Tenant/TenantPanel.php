<?php

namespace App\Filament\Tenant;

use Filament\Panel;
use Illuminate\Database\Eloquent\Model;

class TenantPanel extends Panel
{
    public function getUrl(?Model $tenant = null): ?string
    {
        if (tenancy()->initialized) {
            return route('filament.tenant.pages.dashboard', [
                'tenant' => tenant('shortname') ?: tenant('id'),
            ]);
        }

        return parent::getUrl($tenant);
    }
}
