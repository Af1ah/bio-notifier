<?php

namespace App\Filament\Tenant\Resources\TaskGroups\Pages;

use App\Filament\Tenant\Resources\TaskGroups\TaskGroupResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListTaskGroups extends ListRecords
{
    protected static string $resource = TaskGroupResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make(),
        ];
    }
}
