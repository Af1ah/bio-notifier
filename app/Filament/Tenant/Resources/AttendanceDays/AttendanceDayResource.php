<?php

namespace App\Filament\Tenant\Resources\AttendanceDays;

use App\Filament\Tenant\Resources\AttendanceDays\Pages\ListAttendanceDays;
use App\Filament\Tenant\Resources\AttendanceDays\Schemas\AttendanceDayForm;
use App\Filament\Tenant\Resources\AttendanceDays\Tables\AttendanceDaysTable;
use App\Models\AttendanceDay;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;

class AttendanceDayResource extends Resource
{
    protected static ?string $model = AttendanceDay::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedCalendarDays;

    protected static ?string $navigationLabel = 'Attendance';

    protected static \UnitEnum|string|null $navigationGroup = 'Organisation Management';

    public static function form(Schema $schema): Schema
    {
        return AttendanceDayForm::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return AttendanceDaysTable::configure($table);
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
            'index' => ListAttendanceDays::route('/'),
        ];
    }
}
