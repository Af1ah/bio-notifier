<?php

namespace App\Filament\Tenant\Resources\LeaveTypes;

use App\Filament\Tenant\Resources\LeaveTypes\Pages\CreateLeaveType;
use App\Filament\Tenant\Resources\LeaveTypes\Pages\EditLeaveType;
use App\Filament\Tenant\Resources\LeaveTypes\Pages\ListLeaveTypes;
use App\Filament\Tenant\Resources\LeaveTypes\Pages\ViewLeaveType;
use App\Filament\Tenant\Resources\LeaveTypes\Schemas\LeaveTypeForm;
use App\Filament\Tenant\Resources\LeaveTypes\Schemas\LeaveTypeInfolist;
use App\Filament\Tenant\Resources\LeaveTypes\Tables\LeaveTypesTable;
use App\Models\LeaveType;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;

class LeaveTypeResource extends Resource
{
    protected static ?string $model = LeaveType::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedAdjustmentsHorizontal;

    protected static \UnitEnum|string|null $navigationGroup = 'Payroll';

    protected static ?int $navigationSort = 2;

    protected static ?string $navigationLabel = 'Leave types';

    protected static ?string $modelLabel = 'Leave type';

    protected static ?string $pluralModelLabel = 'Leave types';

    protected static ?string $recordTitleAttribute = 'name';

    public static function form(Schema $schema): Schema
    {
        return LeaveTypeForm::configure($schema);
    }

    public static function infolist(Schema $schema): Schema
    {
        return LeaveTypeInfolist::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return LeaveTypesTable::configure($table);
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
            'index' => ListLeaveTypes::route('/'),
            'create' => CreateLeaveType::route('/create'),
            'view' => ViewLeaveType::route('/{record}'),
            'edit' => EditLeaveType::route('/{record}/edit'),
        ];
    }
}
