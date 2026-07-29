<?php

namespace App\Filament\Tenant\Resources\Branches;

use App\Filament\Tenant\Resources\Branches\Pages\CreateBranch;
use App\Filament\Tenant\Resources\Branches\Pages\EditBranch;
use App\Filament\Tenant\Resources\Branches\Pages\ListBranches;
use App\Filament\Tenant\Resources\Branches\Pages\ViewBranch;
use App\Filament\Tenant\Resources\Branches\Schemas\BranchForm;
use App\Filament\Tenant\Resources\Branches\Tables\BranchesTable;
use App\Models\Branch;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Tables\Table;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Text;
use Filament\Schemas\Components\Group;
use Filament\Schemas\Components\Grid;

class BranchResource extends Resource
{
    protected static ?string $model = Branch::class;

    protected static string|BackedEnum|null $navigationIcon = 'heroicon-o-map-pin';

    protected static \UnitEnum|string|null $navigationGroup = 'Organisation Management';

    protected static ?int $navigationSort = 1;

    public static function form(Schema $schema): Schema
    {
        return BranchForm::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return BranchesTable::configure($table);
    }

    public static function infolist(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make()
                    ->components([
                        Grid::make(4)
                            ->components([
                                Text::make(fn ($record) => $record?->location)
                                    ->icon('heroicon-m-map-pin')
                                    ->color('gray')
                                    ->hidden(fn ($record) => ! $record?->location),
                                Text::make(fn ($record) => $record?->phone_number)
                                    ->icon('heroicon-m-phone')
                                    ->hidden(fn ($record) => ! $record?->phone_number),
                                Text::make(fn ($record) => $record?->pin_code)
                                    ->icon('heroicon-m-hashtag')
                                    ->hidden(fn ($record) => ! $record?->pin_code),
                                Text::make(fn ($record) => $record?->address)
                                    ->icon('heroicon-m-building-office')
                                    ->hidden(fn ($record) => ! $record?->address),
                            ])
                    ])
                    ->hidden(fn ($record) => ! ($record?->location || $record?->phone_number || $record?->pin_code || $record?->address))
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
            'index' => ListBranches::route('/'),
            'create' => CreateBranch::route('/create'),
            'view' => ViewBranch::route('/{record}'),
            'edit' => EditBranch::route('/{record}/edit'),
        ];
    }
}
