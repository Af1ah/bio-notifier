<?php

namespace App\Filament\Master\Resources\Organisations;

use App\Filament\Master\Resources\Organisations\Pages\CreateOrganisation;
use App\Filament\Master\Resources\Organisations\Pages\EditOrganisation;
use App\Filament\Master\Resources\Organisations\Pages\ListOrganisations;
use App\Filament\Master\Resources\Organisations\Pages\ViewOrganisation;
use App\Filament\Master\Resources\Organisations\Schemas\OrganisationForm;
use App\Filament\Master\Resources\Organisations\Schemas\OrganisationInfolist;
use App\Filament\Master\Resources\Organisations\Tables\OrganisationsTable;
use App\Models\Organisation;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;

class OrganisationResource extends Resource
{
    protected static ?string $model = Organisation::class;

    protected static string|BackedEnum|null $navigationIcon = 'heroicon-o-building-office';

    protected static \UnitEnum|string|null $navigationGroup = 'Organisation Management';

    protected static ?int $navigationSort = 1;

    public static function form(Schema $schema): Schema
    {
        return OrganisationForm::configure($schema);
    }

    public static function infolist(Schema $schema): Schema
    {
        return OrganisationInfolist::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return OrganisationsTable::configure($table);
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
            'index' => ListOrganisations::route('/'),
            'create' => CreateOrganisation::route('/create'),
            'view' => ViewOrganisation::route('/{record}'),
            'edit' => EditOrganisation::route('/{record}/edit'),
            'manage-admins' => Pages\ManageOrganisationAdmins::route('/{record}/admins'),
        ];
    }
}
