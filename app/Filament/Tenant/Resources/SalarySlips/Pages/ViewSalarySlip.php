<?php

namespace App\Filament\Tenant\Resources\SalarySlips\Pages;

use App\Filament\Tenant\Actions\SalarySlipActions;
use App\Filament\Tenant\Resources\SalarySlips\SalarySlipResource;
use Filament\Resources\Pages\ViewRecord;

class ViewSalarySlip extends ViewRecord
{
    protected static string $resource = SalarySlipResource::class;

    protected function getHeaderActions(): array
    {
        return [
            ...SalarySlipActions::make(),
        ];
    }
}
