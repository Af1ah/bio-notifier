<?php

namespace App\Filament\Tenant\Resources\Branches\Pages;

use App\Filament\Tenant\Actions\AssignShiftAction;
use App\Filament\Tenant\Actions\EndShiftAssignmentAction;
use App\Filament\Tenant\Resources\Branches\BranchResource;
use Filament\Actions\EditAction;
use Filament\Resources\Pages\ViewRecord;
use Illuminate\Contracts\Support\Htmlable;

class ViewBranch extends ViewRecord
{
    protected static string $resource = BranchResource::class;

    protected function getHeaderActions(): array
    {
        return [
            AssignShiftAction::make('branch'),
            EndShiftAssignmentAction::make('branch'),
            EditAction::make(),
        ];
    }

    public function getTitle(): string|Htmlable
    {
        return $this->record->name ?? 'View Branch';
    }
}
