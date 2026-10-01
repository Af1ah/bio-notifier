<?php

namespace App\Filament\Tenant\Resources\AttendanceCalculationRuns;

use App\Filament\Tenant\Resources\AttendanceCalculationRuns\Pages\ListAttendanceCalculationRuns;
use App\Filament\Tenant\Resources\AttendanceCalculationRuns\Schemas\AttendanceCalculationRunForm;
use App\Filament\Tenant\Resources\AttendanceCalculationRuns\Tables\AttendanceCalculationRunsTable;
use App\Models\AttendanceCalculationRun;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;

class AttendanceCalculationRunResource extends Resource
{
    protected static ?string $model = AttendanceCalculationRun::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedArrowPath;

    protected static \UnitEnum|string|null $navigationGroup = 'Organisation Management';

    protected static ?string $navigationLabel = 'Recalculation runs';

    public static function form(Schema $schema): Schema
    {
        return AttendanceCalculationRunForm::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return AttendanceCalculationRunsTable::configure($table);
    }

    public static function getRelations(): array
    {
        return [
            //
        ];
    }

    public static function getPages(): array
    {
        return [
            'index' => ListAttendanceCalculationRuns::route('/'),
        ];
    }
}
