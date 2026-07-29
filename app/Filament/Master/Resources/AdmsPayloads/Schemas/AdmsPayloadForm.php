<?php

namespace App\Filament\Master\Resources\AdmsPayloads\Schemas;

use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Textarea;
use Filament\Schemas\Schema;

class AdmsPayloadForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                TextInput::make('serial_number')
                    ->required(),
                TextInput::make('tenant_id')
                    ->default(null),
                TextInput::make('table_name')
                    ->default(null),
                TextInput::make('stamp')
                    ->default(null),
                Textarea::make('payload')
                    ->required()
                    ->columnSpanFull(),
                Select::make('status')
                    ->options(['pending' => 'Pending', 'failed' => 'Failed'])
                    ->default('pending')
                    ->required(),
                Textarea::make('error_message')
                    ->default(null)
                    ->columnSpanFull(),
            ]);
    }
}
