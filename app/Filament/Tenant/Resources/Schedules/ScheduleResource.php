<?php

namespace App\Filament\Tenant\Resources\Schedules;

use App\Filament\Tenant\Resources\Schedules\Pages\CreateSchedule;
use App\Filament\Tenant\Resources\Schedules\Pages\EditSchedule;
use App\Filament\Tenant\Resources\Schedules\Pages\ListSchedules;
use App\Filament\Tenant\Resources\Schedules\Schemas\ScheduleForm;
use App\Filament\Tenant\Resources\Schedules\Tables\SchedulesTable;
use App\Models\Schedule;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;

class ScheduleResource extends Resource
{
    protected static ?string $model = Schedule::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedRectangleStack;

    protected static ?string $modelLabel = 'Shift and schedule';

    protected static ?string $pluralModelLabel = 'Shifts and schedules';

    protected static ?string $navigationLabel = 'Shifts and schedules';

    protected static ?string $recordTitleAttribute = 'name';

    public static function form(Schema $schema): Schema
    {
        return ScheduleForm::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return SchedulesTable::configure($table);
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
            'index' => ListSchedules::route('/'),
            'create' => CreateSchedule::route('/create'),
            'edit' => EditSchedule::route('/{record}/edit'),
        ];
    }
}
