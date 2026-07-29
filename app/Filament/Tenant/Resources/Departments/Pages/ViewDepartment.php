<?php

namespace App\Filament\Tenant\Resources\Departments\Pages;

use App\Filament\Tenant\Resources\Departments\DepartmentResource;
use Filament\Actions\EditAction;
use Filament\Resources\Pages\ViewRecord;

class ViewDepartment extends ViewRecord
{
    protected static string $resource = DepartmentResource::class;

    protected function getHeaderActions(): array
    {
        return [
            EditAction::make(),
        ];
    }

    public function getTitle(): string | \Illuminate\Contracts\Support\Htmlable
    {
        return $this->record->name ?? 'View Department';
    }

    protected function hasInfolist(): bool
    {
        return true;
    }
}
