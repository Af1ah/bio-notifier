<?php

namespace App\Filament\Tenant\Resources\Branches\Pages;

use App\Filament\Tenant\Resources\Branches\BranchResource;
use Filament\Actions\EditAction;
use Filament\Resources\Pages\ViewRecord;

class ViewBranch extends ViewRecord
{
    protected static string $resource = BranchResource::class;

    protected function getHeaderActions(): array
    {
        return [
            EditAction::make(),
        ];
    }

    public function getTitle(): string | \Illuminate\Contracts\Support\Htmlable
    {
        return $this->record->name ?? 'View Branch';
    }
}
