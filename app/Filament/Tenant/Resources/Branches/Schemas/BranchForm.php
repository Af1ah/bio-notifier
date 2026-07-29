<?php

namespace App\Filament\Tenant\Resources\Branches\Schemas;

use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Textarea;
use Filament\Schemas\Schema;

class BranchForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([

                TextInput::make('name')
                    ->required(),
                TextInput::make('location')
                    ->default(null),
                Textarea::make('address')
                    ->default(null)
                    ->columnSpanFull(),
                TextInput::make('phone_number')
                    ->tel()
                    ->default(null),
                TextInput::make('pin_code')
                    ->default(null),
            ]);
    }
}
