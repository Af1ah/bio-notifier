<?php

namespace App\Filament\Tenant\Resources\AttendanceDays\Pages;

use App\Filament\Tenant\Actions\RecalculateAttendanceAction;
use App\Filament\Tenant\Resources\AttendanceCalculationRuns\AttendanceCalculationRunResource;
use App\Filament\Tenant\Resources\AttendanceDays\AttendanceDayResource;
use App\Filament\Tenant\Resources\AttendanceDays\Schemas\AttendanceCorrectionForm;
use Filament\Actions\Action;
use Filament\Actions\ActionGroup;
use Filament\Resources\Pages\ListRecords;

class ListAttendanceDays extends ListRecords
{
    protected static string $resource = AttendanceDayResource::class;

    protected function getHeaderActions(): array
    {
        return [
            AttendanceCorrectionForm::action(),
            RecalculateAttendanceAction::make(),
            ActionGroup::make([
                Action::make('recalculationStatus')
                    ->label('Recalculation status')
                    ->icon('heroicon-o-arrow-path')
                    ->authorize(fn (): bool => AttendanceCalculationRunResource::canViewAny())
                    ->url(fn (): string => AttendanceCalculationRunResource::getUrl('index')),
            ])->label('More actions')->icon('heroicon-m-ellipsis-vertical')->iconButton()->color('gray'),
        ];
    }
}
