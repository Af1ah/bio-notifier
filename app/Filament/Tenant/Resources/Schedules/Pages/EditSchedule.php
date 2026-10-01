<?php

namespace App\Filament\Tenant\Resources\Schedules\Pages;

use App\Filament\Tenant\Resources\Schedules\ScheduleResource;
use App\Services\Attendance\ShiftRuleService;
use Filament\Actions\Action;
use Filament\Actions\DeleteAction;
use Filament\Resources\Pages\EditRecord;

class EditSchedule extends EditRecord
{
    protected static string $resource = ScheduleResource::class;

    public static bool $formActionsAreSticky = true;

    protected function getHeaderActions(): array
    {
        return [
            DeleteAction::make(),
        ];
    }

    protected function afterSave(): void
    {
        app(ShiftRuleService::class)->sync($this->record);
    }

    protected function getSaveFormAction(): Action
    {
        return parent::getSaveFormAction()->label('Save shift');
    }
}
