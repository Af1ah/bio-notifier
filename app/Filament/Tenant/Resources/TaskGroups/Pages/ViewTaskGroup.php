<?php

namespace App\Filament\Tenant\Resources\TaskGroups\Pages;

use App\Filament\Tenant\Resources\TaskGroups\TaskGroupResource;
use Filament\Actions\EditAction;
use Filament\Resources\Pages\ViewRecord;

class ViewTaskGroup extends ViewRecord
{
    protected static string $resource = TaskGroupResource::class;

    protected function getHeaderActions(): array
    {
        return [
            EditAction::make(),
        ];
    }

    public function getTitle(): string | \Illuminate\Contracts\Support\Htmlable
    {
        return $this->record->name ?? 'View Task Group';
    }

    protected function hasInfolist(): bool
    {
        return true;
    }
}
