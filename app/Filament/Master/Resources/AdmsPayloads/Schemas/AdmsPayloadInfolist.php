<?php

namespace App\Filament\Master\Resources\AdmsPayloads\Schemas;

use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Schema;

class AdmsPayloadInfolist
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                TextEntry::make('serial_number'),
                TextEntry::make('tenant_id')
                    ->placeholder('-'),
                TextEntry::make('table_name')
                    ->placeholder('-'),
                TextEntry::make('stamp')
                    ->placeholder('-'),
                TextEntry::make('payload')
                    ->columnSpanFull(),
                TextEntry::make('status')
                    ->badge(),
                TextEntry::make('error_message')
                    ->placeholder('-')
                    ->columnSpanFull(),
                TextEntry::make('created_at')
                    ->dateTime()
                    ->placeholder('-'),
                TextEntry::make('updated_at')
                    ->dateTime()
                    ->placeholder('-'),
            ]);
    }
}
