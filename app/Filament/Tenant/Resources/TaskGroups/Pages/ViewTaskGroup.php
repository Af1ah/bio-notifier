<?php

namespace App\Filament\Tenant\Resources\TaskGroups\Pages;

use App\Filament\Tenant\Actions\AssignShiftAction;
use App\Filament\Tenant\Actions\EndShiftAssignmentAction;
use App\Filament\Tenant\Resources\TaskGroups\TaskGroupResource;
use Filament\Actions\EditAction;
use Filament\Resources\Pages\ViewRecord;
use Illuminate\Contracts\Support\Htmlable;

class ViewTaskGroup extends ViewRecord
{
    protected static string $resource = TaskGroupResource::class;

    protected function getHeaderActions(): array
    {
        return [
            AssignShiftAction::make('task_group'),
            EndShiftAssignmentAction::make('task_group'),
            EditAction::make(),
        ];
    }

    public function getTitle(): string|Htmlable
    {
        return $this->record->name ?? 'View Task Group';
    }

    protected function hasInfolist(): bool
    {
        return true;
    }
}
