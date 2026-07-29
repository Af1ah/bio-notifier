<?php

namespace App\Filament\Master\Resources\Organisations\Schemas;

use Filament\Forms\Components\TextInput;
use Filament\Schemas\Schema;

class OrganisationForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                \Filament\Schemas\Components\Section::make('General Information')
                    ->schema([
                TextInput::make('name')
                    ->required(),
                TextInput::make('shortname')
                    ->required()
                    ->unique(ignoreRecord: true)
                    ->alphaDash(),
                TextInput::make('email')
                    ->label('Email address')
                    ->email()
                    ->default(null),
                TextInput::make('phone')
                    ->tel()
                    ->default(null),
                \Filament\Forms\Components\FileUpload::make('logo')
                    ->image()
                    ->acceptedFileTypes(['image/png', 'image/svg+xml', 'image/jpeg', 'image/webp'])
                    ->maxSize(100)
                    ->directory('organisations/logos')
                    ->default(null),
                \Filament\Forms\Components\ColorPicker::make('brand_color')
                    ->default(null),
                \Filament\Forms\Components\Select::make('status')
                    ->options([
                        'active' => 'Active',
                        'inactive' => 'Inactive',
                    ])
                    ->default('active')
                    ->required(),
                ])->columns(2),

                \Filament\Schemas\Components\Section::make('eBioServer Credentials')
                    ->schema([
                        \Filament\Forms\Components\Placeholder::make('webhook_url')
                            ->label('Webhook Post URL')
                            ->hidden(fn (?\App\Models\Organisation $record) => $record === null)
                            ->content(function (?\App\Models\Organisation $record) {
                                if (!$record) return '-';
                                $url = url('/api/ebio/webhook/' . $record->id);
                                return new \Illuminate\Support\HtmlString('
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
