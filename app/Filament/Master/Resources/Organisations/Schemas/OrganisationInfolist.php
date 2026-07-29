<?php

namespace App\Filament\Master\Resources\Organisations\Schemas;

use Filament\Infolists\Components\TextEntry;
use Filament\Infolists\Components\ImageEntry;
use Filament\Infolists\Components\ColorEntry;
use Filament\Schemas\Schema;

class OrganisationInfolist
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                \Filament\Schemas\Components\Section::make('General Information')
                    ->columnSpanFull()
                    ->schema([
                        TextEntry::make('name')
                            ->weight('bold')
                            ->size('lg'),
                        \Filament\Infolists\Components\TextEntry::make('status')
                            ->badge()
                            ->color(fn (string $state): string => match ($state) {
                                'active' => 'success',
                                'inactive' => 'danger',
                                default => 'warning',
                            }),
                        TextEntry::make('shortname')
                            ->label('Slug')
                            ->placeholder('-'),
                        TextEntry::make('email')
                            ->label('Email address')
                            ->placeholder('-'),
                        TextEntry::make('phone')
                            ->placeholder('-'),
                        ImageEntry::make('logo'),
                        ColorEntry::make('brand_color')
                            ->placeholder('-'),
                        TextEntry::make('created_at')
                            ->dateTime()
                            ->placeholder('-'),
                        TextEntry::make('updated_at')
                            ->dateTime()
                            ->placeholder('-'),
                    ])->columns(2),
                
                \Filament\Schemas\Components\Tabs::make('Tabs')
                    ->contained(false)
                    ->extraAttributes(['class' => 'flex justify-center w-full'])
                    ->tabs([
                        \Filament\Schemas\Components\Tabs\Tab::make('eBioServer')
                            ->icon('heroicon-o-server')
                            ->schema([
                                \Filament\Infolists\Components\ViewEntry::make('ebioserver_credentials')
                                    ->label('')
                                    ->view('infolists.components.ebioserver-table')
                            ]),
                        
                        \Filament\Schemas\Components\Tabs\Tab::make('Devices')
                            ->icon('heroicon-o-cpu-chip')
                            ->schema([
                                \Filament\Infolists\Components\ViewEntry::make('devices')
                                    ->label('')
                                    ->view('infolists.components.tenant-devices')
                            ]),
                            
                        \Filament\Schemas\Components\Tabs\Tab::make('Users')
                            ->icon('heroicon-o-users')
                            ->schema([
                                \Filament\Infolists\Components\ViewEntry::make('users')
                                    ->label('')
                                    ->view('infolists.components.tenant-users')
                            ]),
                    ])
                    ->columnSpanFull(),
            ]);
    }
}
