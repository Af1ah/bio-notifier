<?php

namespace App\Filament\Tenant\Resources\PayrollTemplates\Pages;

use App\Filament\Tenant\Resources\PayrollTemplates\PayrollTemplateResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ManageRecords;

class ManagePayrollTemplates extends ManageRecords
{
    protected static string $resource = PayrollTemplateResource::class;

    protected function getHeaderActions(): array
    {
        return [CreateAction::make()];
    }
}
