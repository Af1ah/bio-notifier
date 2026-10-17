<?php

namespace App\Filament\Tenant\Resources;

use Filament\Actions\Action;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteAction;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\ViewAction;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Tables;
use Filament\Tables\Table;
use App\Filament\Tenant\Resources\DeviceCommandResource\Pages;

use App\Models\DeviceCommand;

class DeviceCommandResource extends Resource
{
    protected static ?string $model = DeviceCommand::class;

    protected static string | \BackedEnum | null $navigationIcon = 'heroicon-o-command-line';

    protected static \UnitEnum|string|null $navigationGroup = 'Device Management';

    protected static ?int $navigationSort = 4;

    public static function getNavigationGroup(): ?string
    {
        return 'Device Management';
    }

    public static function canCreate(): bool
    {
        // Device actions must go through the supported dispatch path.
        return false;
    }

    private static function resultLabel(DeviceCommand $record): string
    {
        return match ($record->status) {
            'acknowledged' => 'Completed',
            'failed' => 'Action failed. Contact your administrator.',
            'sent' => 'Awaiting completion',
            'outcome_unknown' => 'Needs review. Contact your administrator.',
            'expired' => 'Expired before execution',
            'cancelled' => 'Cancelled',
            default => 'Queued',
        };
    }

    public static function infolist(Schema $schema): Schema
    {
        return $schema->components([
            Section::make('Device action')->schema([
                \Filament\Infolists\Components\TextEntry::make('device.name')
                    ->label('Target')->placeholder('Company devices'),
                \Filament\Infolists\Components\TextEntry::make('command_type')
                    ->label('Action')
                    ->formatStateUsing(fn (string $state): string => str($state)->replace('_', ' ')->title()->toString()),
                \Filament\Infolists\Components\TextEntry::make('status')->badge(),
                \Filament\Infolists\Components\TextEntry::make('response')
                    ->label('Result')
                    ->state(fn (DeviceCommand $record): string => self::resultLabel($record)),
                \Filament\Infolists\Components\TextEntry::make('sent_at')->dateTime(),
                \Filament\Infolists\Components\TextEntry::make('acknowledged_at')->dateTime(),
                \Filament\Infolists\Components\TextEntry::make('retry_count')->label('Retries'),
            ])->columns(2),
        ]);
    }

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            Section::make('Command Details')
                ->schema([
                    Select::make('device_id')
                        ->relationship('device', 'serial_number')
                        ->required()
                        ->searchable(),
                    Select::make('command_type')
                        ->options([
                            'INFO' => 'Get Device Info',
                            'REBOOT' => 'Reboot Device',
                            'reboot' => 'Reboot Device',
                            'CLEAR' => 'Clear Data',
                            'clear_logs' => 'Clear Logs',
                            'reset_transaction_stamp' => 'Reset Transaction Stamp',
                            'reset_op_stamp' => 'Reset OP Stamp',
                            'unlock_door' => 'Unlock Door',
                            'block_user' => 'Block User',
                            'unblock_user' => 'Unblock User',
                            'enroll_finger' => 'Enroll Finger',
                            'enroll_face' => 'Enroll Face',
                            'DATA' => 'Send Data',
                            'CHECK' => 'Check Connection',
                        ])
                        ->required()
                        ->live(),
                    Textarea::make('command_content')
                        ->required()
                        ->rows(3),
                    Select::make('status')
                        ->options([
                            'pending' => 'Pending',
                            'sent' => 'Sent',
                            'acknowledged' => 'Acknowledged',
                            'failed' => 'Failed',
                            'outcome_unknown' => 'Needs review',
                            'expired' => 'Expired',
                            'cancelled' => 'Cancelled',
                        ])
                        ->default('pending')
                        ->disabled(),
                ])
                ->columns(2)
                ->columnSpanFull(),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('device.name')
                    ->label('Target')
                    ->placeholder('Company devices')
                    ->sortable()
                    ->searchable(),
                Tables\Columns\TextColumn::make('command_type')
                    ->formatStateUsing(fn (string $state): string => match ($state) {
                        'sync_users' => 'Sync users',
                        default => str($state)->replace('_', ' ')->title()->toString(),
                    })
                    ->badge(),
                Tables\Columns\TextColumn::make('command_content')
                    ->label('Action')
                    ->formatStateUsing(fn (DeviceCommand $record): string => str($record->command_type)->replace('_', ' ')->title()->toString())
                    ->limit(40)
                    ->visibleFrom('md'),
                Tables\Columns\TextColumn::make('status')
                    ->badge()
                    ->formatStateUsing(fn (string $state): string => match ($state) {
                        'sent' => 'Sent / awaiting data',
                        'outcome_unknown' => 'Needs review',
                        'expired' => 'Expired',
                        'acknowledged' => 'Completed',
                        default => str($state)->title()->toString(),
                    })
                    ->color(fn (string $state): string => match ($state) {
                        'pending' => 'warning',
                        'sent' => 'info',
                        'acknowledged' => 'success',
                        'failed' => 'danger',
                        default => 'gray',
                    }),
                Tables\Columns\TextColumn::make('sent_at')
                    ->dateTime()
                    ->visibleFrom('md'),
                Tables\Columns\TextColumn::make('acknowledged_at')
                    ->dateTime()
                    ->visibleFrom('md'),
                Tables\Columns\TextColumn::make('retry_count')
                    ->label('Retries')
                    ->visibleFrom('md'),
                Tables\Columns\TextColumn::make('response')
                    ->label('Result')
                    ->formatStateUsing(fn (DeviceCommand $record): string => self::resultLabel($record))
                    ->wrap()
                    ->toggleable(),
            ])
            ->poll(fn () => \App\Models\DeviceCommand::whereIn('status', ['pending', 'sent'])->exists() ? '10s' : null)
            ->defaultSort('created_at', 'desc')
            ->filters([
                Tables\Filters\SelectFilter::make('device')
                    ->relationship('device', 'serial_number'),
                Tables\Filters\SelectFilter::make('status')
                    ->options([
                        'pending' => 'Pending',
                        'sent' => 'Sent',
                        'acknowledged' => 'Acknowledged',
                        'failed' => 'Failed',
                            'outcome_unknown' => 'Needs review',
                            'expired' => 'Expired',
                            'cancelled' => 'Cancelled',
                    ]),
                Tables\Filters\SelectFilter::make('command_type')
                    ->options([
                        'INFO' => 'Get Device Info',
                        'REBOOT' => 'Reboot Device',
                        'reboot' => 'Reboot Device',
                        'CLEAR' => 'Clear Data',
                        'clear_logs' => 'Clear Logs',
                        'reset_transaction_stamp' => 'Reset Transaction Stamp',
                        'reset_op_stamp' => 'Reset OP Stamp',
                        'unlock_door' => 'Unlock Door',
                        'block_user' => 'Block User',
                        'unblock_user' => 'Unblock User',
                        'enroll_finger' => 'Enroll Finger',
                        'enroll_face' => 'Enroll Face',
                        'sync_users' => 'Sync Users',
                        'info' => 'Read device information',
                        'check' => 'Check connection',
                        'query_users' => 'Read device users',
                        'clear_alarm' => 'Clear Alarm',
                        'update_biodata' => 'Update Biometric Template',
                        'update_biophoto' => 'Update User Photo',
                        'DATA' => 'Send Data',
                        'CHECK' => 'Check Connection',
                    ]),
            ])
            ->recordActions([
                \Filament\Actions\ActionGroup::make([
                    ViewAction::make(),
                    Action::make('retry')
                        ->authorize(fn (): bool => \App\Services\Devices\DeviceIntegration::usesEbio())
                        ->icon('heroicon-o-arrow-path')
                        ->color('warning')
                        ->requiresConfirmation()
                        ->visible(fn (DeviceCommand $record) => $record->status === 'failed')
                        ->action(fn (DeviceCommand $record) => $record->retry()),
                    DeleteAction::make(),
                ])
            ])
            ->toolbarActions([
                BulkActionGroup::make([
                    DeleteBulkAction::make(),
                ]),
            ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListDeviceCommands::route('/'),
            'create' => Pages\CreateDeviceCommand::route('/create'),
            'view' => Pages\ViewDeviceCommand::route('/{record}'),
        ];
    }
}
