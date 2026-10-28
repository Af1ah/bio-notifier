<?php

namespace App\Filament\Master\Resources\AdmsPayloads;

use App\Filament\Master\Resources\AdmsPayloads\Pages\CreateAdmsPayload;
use App\Filament\Master\Resources\AdmsPayloads\Pages\EditAdmsPayload;
use App\Filament\Master\Resources\AdmsPayloads\Pages\ListAdmsPayloads;
use App\Filament\Master\Resources\AdmsPayloads\Pages\ViewAdmsPayload;
use App\Filament\Master\Resources\AdmsPayloads\Schemas\AdmsPayloadForm;
use App\Filament\Master\Resources\AdmsPayloads\Schemas\AdmsPayloadInfolist;
use App\Filament\Master\Resources\AdmsPayloads\Tables\AdmsPayloadsTable;
use App\Models\AdmsPayload;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;

class AdmsPayloadResource extends Resource
{
    protected static ?string $model = AdmsPayload::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedRectangleStack;

    protected static ?string $recordTitleAttribute = 'serial_number';

    // Retained for historical references; the retired UI is not accessible.
    public static function canViewAny(): bool
    {
        return false;
    }

    public static function canCreate(): bool
    {
        return false;
    }

    public static function canView(Model $record): bool
    {
        return false;
    }

    public static function canEdit(Model $record): bool
    {
        return false;
    }

    public static function canDelete(Model $record): bool
    {
        return false;
    }

    public static function canDeleteAny(): bool
    {
        return false;
    }

    public static function form(Schema $schema): Schema
    {
        return AdmsPayloadForm::configure($schema);
    }

    public static function infolist(Schema $schema): Schema
    {
        return AdmsPayloadInfolist::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return AdmsPayloadsTable::configure($table);
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
            'index' => ListAdmsPayloads::route('/'),
            'create' => CreateAdmsPayload::route('/create'),
            'view' => ViewAdmsPayload::route('/{record}'),
            'edit' => EditAdmsPayload::route('/{record}/edit'),
        ];
    }
}
