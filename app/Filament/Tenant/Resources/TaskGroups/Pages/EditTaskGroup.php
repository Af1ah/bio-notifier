<?php

namespace App\Filament\Tenant\Resources\TaskGroups\Pages;

use App\Filament\Tenant\Resources\TaskGroups\TaskGroupResource;
use Filament\Actions\DeleteAction;
use Filament\Resources\Pages\EditRecord;

class EditTaskGroup extends EditRecord
{
    protected static string $resource = TaskGroupResource::class;

    protected function getHeaderActions(): array
    {
        return [
            DeleteAction::make(),
        ];
    }
}
