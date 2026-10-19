<?php

namespace App\Filament\Master\Resources\Organisations\Schemas;

use App\Models\Organisation;
use App\Services\Devices\DeviceIntegration;
use Filament\Forms\Components\ColorPicker;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\Placeholder;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\ToggleButtons;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Illuminate\Support\HtmlString;

class OrganisationForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make('General Information')
                    ->schema([
                        TextInput::make('name')
                            ->required(),
                        TextInput::make('shortname')
                            ->required()
                            ->unique(ignoreRecord: true)
                            ->alphaDash(),
                        Select::make('timezone')->label('Organisation timezone')
                            ->options(array_combine(\DateTimeZone::listIdentifiers(), \DateTimeZone::listIdentifiers()))
                            ->default('Asia/Kolkata')->searchable()->required()->rules(['timezone']),
                        TextInput::make('email')
                            ->label('Email address')
                            ->email()
                            ->default(null),
                        TextInput::make('phone')
                            ->tel()
                            ->default(null),
                        FileUpload::make('logo')
                            ->image()
                            ->acceptedFileTypes(['image/png', 'image/svg+xml', 'image/jpeg', 'image/webp'])
                            ->maxSize(100)
                            ->directory('organisations/logos')
                            ->default(null),
                        ColorPicker::make('brand_color')
                            ->default(null),
                        Select::make('status')
                            ->options([
                                'active' => 'Active',
                                'inactive' => 'Inactive',
                            ])
                            ->default('active')
                            ->required(),
                    ])->columns(2),

                Section::make('Device integration')
                    ->schema([
                        ToggleButtons::make('device_integration')
                            ->label('Connection')
                            ->options(DeviceIntegration::options())
                            ->default(DeviceIntegration::GATEWAY)
                            ->inline()
                            ->live()
                            ->required()
                            ->in(array_keys(DeviceIntegration::options())),
                        Placeholder::make('gateway_connection')
                            ->label('Shared gateway')
                            ->content('Manage the shared connection and approved device ownership in the master Device gateway page. Device actions become available after provisioning is synchronized.')
                            ->visible(fn (Get $get): bool => $get('device_integration') === DeviceIntegration::GATEWAY),
                    ]),

                Section::make('eBioServer Credentials')
                    ->visible(fn (Get $get): bool => $get('device_integration') === DeviceIntegration::EBIO)
                    ->dehydratedWhenHidden(false)
                    ->schema([
                        Placeholder::make('webhook_url')
                            ->label('Webhook Post URL')
                            ->hidden(fn (?Organisation $record) => $record === null)
                            ->content(function (?Organisation $record) {
                                if (! $record) {
                                    return '-';
                                }
                                if (! $record->ebio_webhook_token) {
                                    return 'Generate a webhook credential before enabling eBio delivery.';
                                }
                                $url = e(url('/api/ebio/webhook/'.$record->ebio_webhook_token));

                                return new HtmlString('
                                    <div class="flex items-center gap-2">
                                        <code class="font-mono text-sm px-2 py-1 bg-gray-100 dark:bg-gray-800 rounded">'.$url.'</code>
                                        <button type="button" onclick="navigator.clipboard.writeText(\''.$url.'\')" class="text-primary-600 hover:text-primary-500" title="Copy URL">
                                            <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 16H6a2 2 0 01-2-2V6a2 2 0 012-2h8a2 2 0 012 2v2m-6 12h8a2 2 0 002-2v-8a2 2 0 00-2-2h-8a2 2 0 00-2 2v8a2 2 0 002 2z"></path></svg>
                                        </button>
                                    </div>
                                ');
                            })
                            ->columnSpanFull(),
                        TextInput::make('ebio_url')
                            ->label('eBioServer Base URL')
                            ->url()
                            ->maxLength(255)
                            ->columnSpanFull()
                            ->helperText('The main URL of your eBioServer instance (e.g., http://192.168.1.100)'),
                        TextInput::make('ebio_aes_password')
                            ->label('Webhook Encryption Password')
                            ->password()
                            ->helperText('Matches the password in eBioServer Webhook settings. Leave blank if encryption is disabled in eBioServer.')
                            ->columnSpanFull(),
                        TextInput::make('ebio_soap_username')
                            ->label('eBioServer Admin Username')
                            ->helperText('Required only if you want to sync users to/from the device.')
                            ->columnSpanFull(),
                        TextInput::make('ebio_soap_password')
                            ->label('eBioServer Admin Password')
                            ->password()
                            ->columnSpanFull(),
                    ])->columns(1),
            ]);
    }
}
