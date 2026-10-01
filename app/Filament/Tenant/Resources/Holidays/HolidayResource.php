<?php

namespace App\Filament\Tenant\Resources\Holidays;

use App\Filament\Tenant\Resources\Holidays\Pages\ManageHolidays;
use App\Models\Holiday;
use BackedEnum;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteAction;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\CheckboxList;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

class HolidayResource extends Resource
{
    protected static ?string $model = Holiday::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedCalendarDateRange;

    protected static \UnitEnum|string|null $navigationGroup = 'Organisation Management';

    protected static ?string $recordTitleAttribute = 'name';

    public static function form(Schema $schema): Schema
    {
        return $schema
            ->components([
                TextInput::make('name')->required()->maxLength(255),
                DatePicker::make('starts_on')->required()->default(today()), DatePicker::make('ends_on')->required()->default(today())->rule('after_or_equal:starts_on'),
                Select::make('recurrence')->options(['none' => 'Does not repeat', 'yearly' => 'Yearly on the same date', 'monthly_weekday' => 'Monthly by weekday'])->default('none')->required()->live(),
                TextInput::make('start_year')->numeric()->visible(fn ($get) => $get('recurrence') === 'yearly')->default(fn () => now()->year), TextInput::make('end_year')->numeric()->visible(fn ($get) => $get('recurrence') === 'yearly'),
                Select::make('monthly_weekday')
                    ->label('Weekday')
                    ->options([0 => 'Sunday', 1 => 'Monday', 2 => 'Tuesday', 3 => 'Wednesday', 4 => 'Thursday', 5 => 'Friday', 6 => 'Saturday'])
                    ->visible(fn ($get) => $get('recurrence') === 'monthly_weekday')
                    ->required(fn ($get) => $get('recurrence') === 'monthly_weekday'),
                Select::make('monthly_pattern')
                    ->label('Weeks of the month')
                    ->options(['even' => 'Even weeks (2nd and 4th)', 'odd' => 'Odd weeks (1st, 3rd and 5th)', 'custom' => 'Choose weeks'])
                    ->default('even')
                    ->visible(fn ($get) => $get('recurrence') === 'monthly_weekday')
                    ->required(fn ($get) => $get('recurrence') === 'monthly_weekday')
                    ->live(),
                CheckboxList::make('monthly_weeks')
                    ->label('Selected weeks')
                    ->options([1 => 'First', 2 => 'Second', 3 => 'Third', 4 => 'Fourth', 5 => 'Fifth'])
                    ->columns(5)
                    ->visible(fn ($get) => $get('recurrence') === 'monthly_weekday' && $get('monthly_pattern') === 'custom')
                    ->required(fn ($get) => $get('recurrence') === 'monthly_weekday' && $get('monthly_pattern') === 'custom'),
                Toggle::make('active')->default(true),
                Select::make('branches')->relationship('branches', 'name')->multiple()->searchable()->preload()->label('Branches (blank means all)'),
                Select::make('departments')->relationship('departments', 'name')->multiple()->searchable()->preload(),
                Select::make('taskGroups')->relationship('taskGroups', 'name')->multiple()->searchable()->preload(),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('name')->searchable()->sortable(), TextColumn::make('starts_on')->date(), TextColumn::make('ends_on')->date(), TextColumn::make('recurrence')->badge(), TextColumn::make('occurrences_count')->counts('occurrences')->label('Dates'), IconColumn::make('active')->boolean(),
            ])
            ->filters([
                //
            ])
            ->recordActions([
                EditAction::make(),
                DeleteAction::make(),
            ])
            ->toolbarActions([
                BulkActionGroup::make([
                    DeleteBulkAction::make(),
                ]),
            ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => ManageHolidays::route('/'),
        ];
    }
}
