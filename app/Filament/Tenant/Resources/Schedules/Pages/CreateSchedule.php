<?php

namespace App\Filament\Tenant\Resources\Schedules\Pages;

use App\Filament\Tenant\Resources\Schedules\ScheduleResource;
use App\Services\Attendance\ShiftRuleService;
use Filament\Actions\Action;
use Filament\Resources\Pages\CreateRecord;

class CreateSchedule extends CreateRecord
{
    protected static string $resource = ScheduleResource::class;

    public static bool $formActionsAreSticky = true;

    protected function afterCreate(): void
    {
        app(ShiftRuleService::class)->sync($this->record);
    }

    protected function getCreateFormAction(): Action
    {
        return parent::getCreateFormAction()->label('Create shift');
    }
}
