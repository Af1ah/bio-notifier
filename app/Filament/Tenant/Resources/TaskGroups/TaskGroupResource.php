<?php

namespace App\Filament\Tenant\Resources\TaskGroups;

use App\Filament\Tenant\Resources\TaskGroups\Pages\CreateTaskGroup;
use App\Filament\Tenant\Resources\TaskGroups\Pages\EditTaskGroup;
use App\Filament\Tenant\Resources\TaskGroups\Pages\ListTaskGroups;
use App\Filament\Tenant\Resources\TaskGroups\Pages\ViewTaskGroup;
use App\Filament\Tenant\Resources\TaskGroups\Schemas\TaskGroupForm;
use App\Filament\Tenant\Resources\TaskGroups\Tables\TaskGroupsTable;
use App\Models\TaskGroup;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Tables\Table;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Text;
use Filament\Support\Icons\Heroicon;

class TaskGroupResource extends Resource
{
    protected static ?string $model = TaskGroup::class;

    protected static ?string $recordTitleAttribute = 'name';

    public static function getGlobalSearchResultIcon(\Illuminate\Database\Eloquent\Model $record): string
    {
        return 'heroicon-o-user-group';
    }

    protected static string|BackedEnum|null $navigationIcon = 'heroicon-o-user-group';

    protected static \UnitEnum|string|null $navigationGroup = 'Organisation Management';

    protected static ?int $navigationSort = 3;

    public static function form(Schema $schema): Schema
    {
        return TaskGroupForm::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return TaskGroupsTable::configure($table);
    }

    public static function infolist(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make()
                    ->components([
                        Text::make(fn ($record) => $record?->description)
                            ->color('gray')
                    ])
                    ->hidden(fn ($record) => ! $record?->description)
            ]);
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
            'index' => ListTaskGroups::route('/'),
            'create' => CreateTaskGroup::route('/create'),
            'view' => ViewTaskGroup::route('/{record}'),
            'edit' => EditTaskGroup::route('/{record}/edit'),
        ];
    }
}
