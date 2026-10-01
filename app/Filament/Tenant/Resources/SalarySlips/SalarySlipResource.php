<?php

namespace App\Filament\Tenant\Resources\SalarySlips;

use App\Filament\Tenant\Resources\SalarySlips\Pages\ListSalarySlips;
use App\Filament\Tenant\Resources\SalarySlips\Pages\ViewSalarySlip;
use App\Filament\Tenant\Resources\SalarySlips\Schemas\SalarySlipInfolist;
use App\Filament\Tenant\Resources\SalarySlips\Tables\SalarySlipsTable;
use App\Models\SalarySlip;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;

class SalarySlipResource extends Resource
{
    protected static ?string $model = SalarySlip::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedBanknotes;

    protected static \UnitEnum|string|null $navigationGroup = 'Payroll';

    protected static ?int $navigationSort = 3;

    protected static ?string $navigationLabel = 'Salary slips';

    protected static ?string $modelLabel = 'Salary slip';

    protected static ?string $pluralModelLabel = 'Salary slips';

    protected static ?string $recordTitleAttribute = 'id';

    public static function infolist(Schema $schema): Schema
    {
        return SalarySlipInfolist::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return SalarySlipsTable::configure($table);
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
            'index' => ListSalarySlips::route('/'),
            'view' => ViewSalarySlip::route('/{record}'),
        ];
    }
}
