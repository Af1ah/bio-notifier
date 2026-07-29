<?php

namespace App\Filament\Tenant\Resources\Departments\Schemas;

use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Select;
use Filament\Schemas\Schema;

class DepartmentForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([

                TextInput::make('name')
                    ->required(),
                Select::make('branches')
                    ->relationship('branches', 'name')
                    ->getOptionLabelFromRecordUsing(fn ($record) => $record->display_name)
                    ->multiple()
                    ->searchable()
                    ->preload(),
            ]);
    }
}
