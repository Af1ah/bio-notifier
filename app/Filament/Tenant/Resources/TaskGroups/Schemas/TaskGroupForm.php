<?php

namespace App\Filament\Tenant\Resources\TaskGroups\Schemas;

use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Textarea;
use Filament\Schemas\Schema;

class TaskGroupForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                TextInput::make('name')
                    ->required(),
                Textarea::make('description')
                    ->default(null)
                    ->columnSpanFull(),
            ]);
    }
}
