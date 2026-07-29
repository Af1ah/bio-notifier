<?php

namespace App\Filament\Tenant\Resources\Departments;

use App\Filament\Tenant\Resources\Departments\Pages\CreateDepartment;
use App\Filament\Tenant\Resources\Departments\Pages\EditDepartment;
use App\Filament\Tenant\Resources\Departments\Pages\ListDepartments;
use App\Filament\Tenant\Resources\Departments\Pages\ViewDepartment;
use App\Filament\Tenant\Resources\Departments\Schemas\DepartmentForm;
use App\Filament\Tenant\Resources\Departments\Tables\DepartmentsTable;
use App\Models\Department;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Tables\Table;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Text;
use Filament\Support\Icons\Heroicon;

class DepartmentResource extends Resource
{
    protected static ?string $model = Department::class;

    protected static string|BackedEnum|null $navigationIcon = 'heroicon-o-building-office-2';

    protected static \UnitEnum|string|null $navigationGroup = 'Organisation Management';

    protected static ?int $navigationSort = 2;

    public static function form(Schema $schema): Schema
    {
        return DepartmentForm::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return DepartmentsTable::configure($table);
    }

    public static function infolist(Schema $schema): Schema
    {
        return $schema->components([]);
    }

    public static function getRelations(): array
    {
        return [
            RelationManagers\UsersRelationManager::class,
        ];
    }

    public static function getPages(): array
    {
        return [
            'index' => ListDepartments::route('/'),
            'create' => CreateDepartment::route('/create'),
            'view' => ViewDepartment::route('/{record}'),
            'edit' => EditDepartment::route('/{record}/edit'),
        ];
    }
}
