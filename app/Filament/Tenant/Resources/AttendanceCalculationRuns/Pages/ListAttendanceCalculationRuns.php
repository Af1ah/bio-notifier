<?php

namespace App\Filament\Tenant\Resources\AttendanceCalculationRuns\Pages;

use App\Filament\Tenant\Resources\AttendanceCalculationRuns\AttendanceCalculationRunResource;
use Filament\Resources\Pages\ListRecords;

class ListAttendanceCalculationRuns extends ListRecords
{
    protected static string $resource = AttendanceCalculationRunResource::class;
}
