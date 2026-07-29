<?php

namespace App\Filament\Tenant\Resources\AttendanceLogResource\Pages;

use Filament\Resources\Pages\ListRecords;
use App\Filament\Tenant\Resources\AttendanceLogResource;

class ListAttendanceLogs extends ListRecords
{
    protected static string $resource = AttendanceLogResource::class;

    protected function getHeaderActions(): array
    {
        return [
            //
        ];
    }
}
