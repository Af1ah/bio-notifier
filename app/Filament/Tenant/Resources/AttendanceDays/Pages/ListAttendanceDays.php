<?php

namespace App\Filament\Tenant\Resources\AttendanceDays\Pages;

use App\Filament\Tenant\Resources\AttendanceDays\AttendanceDayResource;
use App\Filament\Tenant\Resources\AttendanceDays\Schemas\AttendanceCorrectionForm;
use Filament\Resources\Pages\ListRecords;

class ListAttendanceDays extends ListRecords
{
    protected static string $resource = AttendanceDayResource::class;

    protected function getHeaderActions(): array
    {
        return [AttendanceCorrectionForm::action()];
    }
}
