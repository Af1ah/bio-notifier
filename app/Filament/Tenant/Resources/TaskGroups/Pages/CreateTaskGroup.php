<?php

namespace App\Filament\Tenant\Resources\TaskGroups\Pages;

use App\Filament\Tenant\Resources\TaskGroups\TaskGroupResource;
use Filament\Resources\Pages\CreateRecord;

class CreateTaskGroup extends CreateRecord
{
    protected static string $resource = TaskGroupResource::class;
}
