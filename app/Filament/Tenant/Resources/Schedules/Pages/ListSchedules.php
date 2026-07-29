<?php

namespace App\Filament\Tenant\Resources\Schedules\Pages;

use App\Filament\Tenant\Resources\Schedules\ScheduleResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListSchedules extends ListRecords
{
    protected static string $resource = ScheduleResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make(),
        ];
    }
}
