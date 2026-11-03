<?php

namespace App\Filament\Tenant\Resources\WorkLocations;

use App\Filament\Tenant\Resources\WorkLocations\Pages\ManageWorkLocations;
use App\Models\WorkLocation;
use Filament\Actions\Action;
use Filament\Actions\ActionGroup;
use Filament\Actions\EditAction;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Forms\Components\ViewField;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

class WorkLocationResource extends Resource
{
    protected static ?string $model = WorkLocation::class;

    protected static ?string $recordTitleAttribute = 'name';

    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-map-pin';

    protected static string|\UnitEnum|null $navigationGroup = 'Organisation Management';

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            TextInput::make('name')->required()->maxLength(255),
            Select::make('branch_id')->label('Branch')->relationship('branch', 'name')->searchable()->placeholder('Work location without a branch'),
            TextInput::make('latitude')->numeric()->minValue(-90)->maxValue(90)->required()->live(onBlur: true),
            TextInput::make('longitude')->numeric()->minValue(-180)->maxValue(180)->required()->live(onBlur: true),
            TextInput::make('radius_meters')->label('Allowed radius')->suffix('m')->integer()->minValue(10)->maxValue(10000)->default(100)->required()->live(onBlur: true),
            Toggle::make('is_active')->label('Active')->default(true),
            ViewField::make('map')->view('filament.forms.work-location-map')->dehydrated(false)->columnSpanFull(),
        ])->columns(2);
    }

    public static function table(Table $table): Table
    {
        return $table->columns([
            TextColumn::make('name')->searchable()->sortable(),
            TextColumn::make('branch.name')->placeholder('Work location')->visibleFrom('md'),
            TextColumn::make('radius_meters')->label('Radius')->suffix(' m'),
            IconColumn::make('is_active')->label('Active')->boolean(),
        ])->recordActions([ActionGroup::make([
            EditAction::make()->modalWidth('3xl'),
            Action::make('map')->label('Open in Google Maps')->icon('heroicon-o-arrow-top-right-on-square')
                ->url(fn (WorkLocation $record) => $record->google_maps_url)->openUrlInNewTab(),
        ])])->defaultSort('name')->defaultKeySort()->paginated([10, 25, 50])->defaultPaginationPageOption(10)->enforcePaginationPageOptions();
    }

    public static function getPages(): array
    {
        return ['index' => ManageWorkLocations::route('/')];
    }
}
