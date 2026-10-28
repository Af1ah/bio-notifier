<?php

namespace App\Filament\Master\Resources\Organisations\Schemas;

use App\Models\Organisation;
use App\Services\Devices\DeviceIntegration;
use Filament\Infolists\Components\ColorEntry;
use Filament\Infolists\Components\ImageEntry;
use Filament\Infolists\Components\TextEntry;
use Filament\Infolists\Components\ViewEntry;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Tabs;
use Filament\Schemas\Components\Tabs\Tab;
use Filament\Schemas\Schema;

class OrganisationInfolist
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make('General Information')
                    ->columnSpanFull()
                    ->schema([
                        TextEntry::make('name')
                            ->weight('bold')
                            ->size('lg'),
                        TextEntry::make('status')
                            ->badge()
                            ->color(fn (string $state): string => match ($state) {
                                'active' => 'success',
                                'inactive' => 'danger',
                                default => 'warning',
                            }),
                        TextEntry::make('shortname')
                            ->label('Slug')
                            ->placeholder('-'),
                        TextEntry::make('device_integration')
                            ->label('Device integration')
                            ->formatStateUsing(fn (?string $state): string => DeviceIntegration::options()[$state ?? DeviceIntegration::EBIO] ?? 'Unknown')
                            ->badge(),
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

                Tabs::make('Tabs')
                    ->contained(false)
                    ->extraAttributes(['class' => 'flex justify-center w-full'])
                    ->tabs([
                        Tab::make('eBioServer')
                            ->visible(fn (Organisation $record): bool => DeviceIntegration::usesEbio($record))
                            ->icon('heroicon-o-server')
                            ->schema([
                                ViewEntry::make('ebioserver_credentials')
                                    ->label('')
                                    ->view('infolists.components.ebioserver-table'),
                            ]),

                        Tab::make('ADMS gateway')
                            ->visible(fn (Organisation $record): bool => ! DeviceIntegration::usesEbio($record))
                            ->icon('heroicon-o-server')
                            ->schema([
                                TextEntry::make('gateway_connection')
                                    ->label('Shared gateway')
                                    ->state('Company administrators add devices in their workspace. Registration connects them to the shared gateway automatically.'),
                            ]),

                    ])
                    ->columnSpanFull(),
            ]);
    }
}
