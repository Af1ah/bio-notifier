<?php

namespace App\Filament\Tenant\Resources\EmployeeLeaves;

use App\Filament\Tenant\Resources\EmployeeLeaves\Pages\CreateEmployeeLeave;
use App\Filament\Tenant\Resources\EmployeeLeaves\Pages\EditEmployeeLeave;
use App\Filament\Tenant\Resources\EmployeeLeaves\Pages\ListEmployeeLeaves;
use App\Filament\Tenant\Resources\EmployeeLeaves\Pages\ViewEmployeeLeave;
use App\Filament\Tenant\Resources\EmployeeLeaves\Schemas\EmployeeLeaveForm;
use App\Filament\Tenant\Resources\EmployeeLeaves\Schemas\EmployeeLeaveInfolist;
use App\Filament\Tenant\Resources\EmployeeLeaves\Tables\EmployeeLeavesTable;
use App\Models\EmployeeLeave;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;

class EmployeeLeaveResource extends Resource
{
    protected static ?string $model = EmployeeLeave::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedCalendarDateRange;

    protected static \UnitEnum|string|null $navigationGroup = 'Payroll';

    protected static ?int $navigationSort = 1;

    protected static ?string $navigationLabel = 'Employee leave';

    protected static ?string $modelLabel = 'Employee leave';

    protected static ?string $pluralModelLabel = 'Employee leave';

    protected static ?string $recordTitleAttribute = 'id';

    public static function form(Schema $schema): Schema
    {
        return EmployeeLeaveForm::configure($schema);
    }

    public static function infolist(Schema $schema): Schema
    {
        return EmployeeLeaveInfolist::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return EmployeeLeavesTable::configure($table);
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
            'index' => ListEmployeeLeaves::route('/'),
            'create' => CreateEmployeeLeave::route('/create'),
            'view' => ViewEmployeeLeave::route('/{record}'),
            'edit' => EditEmployeeLeave::route('/{record}/edit'),
        ];
    }
}
