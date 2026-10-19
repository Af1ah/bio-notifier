<?php

namespace App\Filament\Tenant\Resources;

use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\ViewAction;
use Filament\Forms\Components\CheckboxList;
use Filament\Forms\Components\TextInput;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Tables;
use Filament\Tables\Table;
use App\Filament\Tenant\Resources\DeviceResource\Pages;
use App\Filament\Tenant\Resources\DeviceResource\RelationManagers;

use App\Models\Device;

class DeviceResource extends Resource
{
    protected static ?string $model = Device::class;

    protected static string | \BackedEnum | null $navigationIcon = 'heroicon-o-cpu-chip';

    protected static ?int $navigationSort = 1;

    protected static \UnitEnum|string|null $navigationGroup = 'Device Management';

    //

    public static function canCreate(): bool
    {
        return true;
    }

    public static function canEdit(\Illuminate\Database\Eloquent\Model $record): bool
    {
        return true;
    }

    public static function canDelete(\Illuminate\Database\Eloquent\Model $record): bool
    {
        return false;
    }

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            Section::make('Device details')
                ->description('Update the local name and location used by Bio-Notifier.')
                ->schema([
                    TextInput::make('serial_number')
                        ->label('Serial number')
                        ->disabled()
                        ->dehydrated(false),
                    TextInput::make('name')
                        ->label('Device name')
                        ->required()
                        ->maxLength(255),
                    TextInput::make('options.location')
                        ->label('Location')
                        ->maxLength(255),
                    CheckboxList::make('options.supported_verification_formats')
                        ->label('Supported verification methods')
                        ->options(Device::VERIFICATION_FORMAT_OPTIONS)
                        ->default(['rfid'])
                        ->afterStateHydrated(fn ($component, $state) => $component->state($state ?: ['rfid']))
                        ->columns(2)
                        ->helperText('Only compatible biometric data can be sent to this device. RFID card is selected by default.'),
                ]),
        ]);
    }

    public static function infolist(Schema $schema): Schema
    {
        return $schema->components([
            \Filament\Infolists\Components\TextEntry::make('serial_number'),
            \Filament\Infolists\Components\TextEntry::make('name'),
            \Filament\Infolists\Components\TextEntry::make('status')->label('Connection')->getStateUsing(fn (Device $record) => $record->isOnline() ? 'Connected' : ($record->last_activity_at ? 'Offline' : 'Waiting for connection')),
            \Filament\Infolists\Components\TextEntry::make('ip_address')->label('Device IP address')->placeholder('Not recorded'),
            \Filament\Infolists\Components\TextEntry::make('model')->label('Model')->placeholder('Not received yet'),
            \Filament\Infolists\Components\TextEntry::make('options.location')
                ->label('Location'),
            \Filament\Infolists\Components\TextEntry::make('verification_formats')
                ->label('Supported verification methods')
                ->state(fn (Device $record): string => collect($record->verificationFormats())
                    ->map(fn (string $format): string => Device::VERIFICATION_FORMAT_OPTIONS[$format])
                    ->join(', ')),
            \Filament\Infolists\Components\TextEntry::make('last_activity_at')
                ->label('Last connected')
                ->timezone(\App\Services\Support\TenantTimezone::get())
                ->dateTime(),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->poll('30s')
            ->emptyStateHeading('Add your first attendance device')
            ->emptyStateDescription('Connect a device to collect attendance for your employees.')
            ->columns([
                Tables\Columns\TextColumn::make('serial_number')
                    ->searchable()
                    ->sortable()
                    ->visibleFrom('md'),
                Tables\Columns\TextColumn::make('name')
                    ->searchable(),
                Tables\Columns\TextColumn::make('options.location')
                    ->label('Location')
                    ->searchable()
                    ->toggleable(),
                Tables\Columns\TextColumn::make('status')
                    ->badge()
                    ->getStateUsing(fn (Device $record): string => $record->isOnline() ? 'online' : ($record->last_activity_at ? 'offline' : 'waiting'))
                    ->formatStateUsing(fn (string $state): string => match ($state) {
                        'online' => 'Connected', 'offline' => 'Offline', default => 'Waiting for connection',
                    })
                    ->color(fn (string $state): string => match ($state) {
                        'online' => 'success',
                        'offline' => 'danger',
                        default => 'warning',
                    })
                    ->visibleFrom('md'),
                Tables\Columns\TextColumn::make('last_activity_at')
                    ->label('Last connected')
                    ->timezone(\App\Services\Support\TenantTimezone::get())
                    ->date('M j, Y')
                    ->description(fn (Device $record): ?string => $record->last_activity_at?->setTimezone(\App\Services\Support\TenantTimezone::get())->format('H:i:s'))
                    ->sortable()
                    ->toggleable(),
                Tables\Columns\TextColumn::make('last_sync_at')
                    ->label('Last Sync')
                    ->timezone(\App\Services\Support\TenantTimezone::get())
                    ->date('M j, Y')
                    ->description(fn (Device $record): ?string => $record->last_sync_at?->setTimezone(\App\Services\Support\TenantTimezone::get())->format('H:i:s'))
                    ->sortable()
                    ->toggleable()
                    ->visibleFrom('md'),
            ])
            ->filters([
                Tables\Filters\SelectFilter::make('status')
                    ->options([
                        'online' => 'Online',
                        'offline' => 'Offline',
                        'unknown' => 'Unknown',
                    ]),
            ])
            ->recordActions([
                \Filament\Actions\ActionGroup::make([
                    ViewAction::make(),
                    ...self::connectionActions(),
                    \Filament\Actions\Action::make('reboot')
                        ->authorize(fn (): bool => \App\Services\Devices\DeviceIntegration::usesEbio())
                        ->label('Reboot Device')
                        ->icon('heroicon-o-power')
                        ->requiresConfirmation()
                        ->action(function (Device $record) {
                            app(\App\Services\Devices\DeviceCommandService::class)->request($record, 'reboot');
                            \Filament\Notifications\Notification::make()
                                ->title('Command Queued')
                                ->body('Reboot command queued.')
                                ->success()
                                ->send();
                        }),
                    \Filament\Actions\Action::make('clearLogs')
                        ->authorize(fn (): bool => \App\Services\Devices\DeviceIntegration::usesEbio())
                        ->label('Clear Logs')
                        ->icon('heroicon-o-trash')
                        ->requiresConfirmation()
                        ->color('danger')
                        ->action(function (Device $record) {
                            app(\App\Services\Devices\DeviceCommandService::class)->request($record, 'clear_logs');
                            \Filament\Notifications\Notification::make()
                                ->title('Command Queued')
                                ->body('Clear logs command queued.')
                                ->success()
                                ->send();
                        }),
                    \Filament\Actions\Action::make('resetTransactionStamp')
                        ->authorize(fn (Device $record): bool => app(\App\Services\Devices\DeviceCommandService::class)->available($record, 'reset_transaction_stamp'))
                        ->label('Reset Transaction Stamp')
                        ->icon('heroicon-o-arrow-down-tray')
                        ->requiresConfirmation()
                        ->action(function (Device $record) {
                            app(\App\Services\Devices\DeviceCommandService::class)->request($record, 'reset_transaction_stamp');
                            \Filament\Notifications\Notification::make()
                                ->title('Command Queued')
                                ->body('Reset transaction stamp command queued.')
                                ->success()
                                ->send();
                        }),
                    \Filament\Actions\Action::make('resetOPStamp')
                        ->authorize(fn (Device $record): bool => app(\App\Services\Devices\DeviceCommandService::class)->available($record, 'reset_op_stamp'))
                        ->label('Reset OP Stamp')
                        ->icon('heroicon-o-arrow-path')
                        ->requiresConfirmation()
                        ->action(function (Device $record) {
                            app(\App\Services\Devices\DeviceCommandService::class)->request($record, 'reset_op_stamp');
                            \Filament\Notifications\Notification::make()
                                ->title('Command Queued')
                                ->body('Reset OP stamp command queued.')
                                ->success()
                                ->send();
                        }),
                    \Filament\Actions\Action::make('unlockDoor')
                        ->authorize(fn (Device $record): bool => app(\App\Services\Devices\DeviceCommandService::class)->available($record, 'unlock_door'))
                        ->label('Unlock Door')
                        ->icon('heroicon-o-lock-open')
                        ->requiresConfirmation()
                        ->action(function (Device $record) {
                            app(\App\Services\Devices\DeviceCommandService::class)->request($record, 'unlock_door');
                            \Filament\Notifications\Notification::make()
                                ->title('Command Queued')
                                ->body('Unlock door command queued.')
                                ->success()
                                ->send();
                        }),
                ])->icon('heroicon-m-ellipsis-vertical'),
            ])
            ->toolbarActions([]);
    }

    public static function getRelations(): array
    {
        return [
            RelationManagers\AttendanceLogsRelationManager::class,
            RelationManagers\DeviceCommandsRelationManager::class,
            RelationManagers\DeviceUsersRelationManager::class,
        ];
    }

    public static function connectionActions(): array
    {
        $actions = [\Filament\Actions\Action::make('connectionSetup')
            ->label('Connection setup')->icon('heroicon-o-wifi')
            ->authorize(fn (): bool => tenant()?->device_integration === \App\Services\Devices\DeviceIntegration::GATEWAY && auth('web')->user()?->has_login)
            ->modalHeading('Connect your device')->modalSubmitAction(false)->modalCancelActionLabel('Done')
            ->modalContent(fn (Device $record) => view('filament.tenant.devices.connection-setup', app(\App\Services\Devices\TenantDeviceRegistration::class)->setup($record)))];
        foreach (['check' => ['Check connection', 'heroicon-o-signal'], 'info' => ['Refresh device details', 'heroicon-o-information-circle'], 'query_users' => ['Read device users', 'heroicon-o-users']] as $operation => [$label, $icon]) {
            $actions[] = \Filament\Actions\Action::make($operation)->label($label)->icon($icon)
                ->authorize(fn (Device $record): bool => app(\App\Services\Devices\DeviceCommandService::class)->available($record, $operation))
                ->action(function (Device $record) use ($operation) {
                    app(\App\Services\Devices\DeviceCommandService::class)->request($record, $operation);
                    \Filament\Notifications\Notification::make()->title('Request sent')->body('Track its progress in Device actions. Offline devices respond when they reconnect.')->success()->send();
                });
        }

        return $actions;
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListDevices::route('/'),
            'view' => Pages\ViewDevice::route('/{record}'),
            'edit' => Pages\EditDevice::route('/{record}/edit'),
        ];
    }
}
