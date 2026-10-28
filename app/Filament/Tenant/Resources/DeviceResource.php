<?php

namespace App\Filament\Tenant\Resources;

use App\Filament\Tenant\Resources\DeviceResource\Pages;
use App\Filament\Tenant\Resources\DeviceResource\RelationManagers;
use App\Models\Device;
use App\Services\Devices\DeviceCommandService;
use App\Services\Devices\DeviceIntegration;
use App\Services\Devices\DeviceProfilePolicy;
use App\Services\Devices\Gateway\GatewayCommands;
use App\Services\Devices\TenantDeviceRegistration;
use App\Services\Support\TenantTimezone;
use Filament\Actions\Action;
use Filament\Actions\ActionGroup;
use Filament\Actions\ViewAction;
use Filament\Forms\Components\Checkbox;
use Filament\Forms\Components\CheckboxList;
use Filament\Forms\Components\Radio;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Infolists\Components\TextEntry;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

class DeviceResource extends Resource
{
    protected static ?string $model = Device::class;

    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-cpu-chip';

    protected static ?int $navigationSort = 1;

    protected static \UnitEnum|string|null $navigationGroup = 'Device Management';

    //

    public static function canCreate(): bool
    {
        return true;
    }

    public static function canEdit(Model $record): bool
    {
        return true;
    }

    public static function canDelete(Model $record): bool
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
                    Select::make('options.direction')
                        ->label('Direction')->options(Device::DIRECTION_OPTIONS)->default('DEVICE')->required()
                        ->afterStateHydrated(fn ($component, $state) => $component->state($state ?: 'DEVICE'))
                        ->in(array_keys(Device::DIRECTION_OPTIONS))
                        ->visible(fn (): bool => ! DeviceIntegration::usesEbio())
                        ->helperText('Applies to newly received punches. Saved raw status and existing attendance stay unchanged. A terminal punch-state command is not verified for every firmware.'),
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
            TextEntry::make('serial_number'),
            TextEntry::make('options.timezone')->label('Device time zone')->placeholder('Not configured'),
            TextEntry::make('name'),
            TextEntry::make('attendance_direction')->label('Direction')->getStateUsing(fn (Device $record): string => Device::DIRECTION_OPTIONS[$record->attendanceDirection()])->visible(fn (): bool => ! DeviceIntegration::usesEbio()),
            TextEntry::make('protocol_profile')->label('Protocol family')
                ->visible(fn () => ! DeviceIntegration::usesEbio())
                ->getStateUsing(fn (Device $record) => match (DeviceProfilePolicy::name($record)) {
                    'legacy-fp' => 'Legacy fingerprint', 'legacy-face' => 'Conventional face',
                    'visible-face-ta' => 'Visible-light face', default => 'Unknown — refresh device details',
                })
                ->helperText('Family identification limits commands. It does not prove enrollment or every firmware operation.'),
            TextEntry::make('status')->label('Connection')->getStateUsing(fn (Device $record) => $record->isOnline() ? 'Connected' : ($record->last_activity_at ? 'Offline' : 'Waiting for connection')),
            TextEntry::make('ip_address')->label('Device IP address')->placeholder('Not recorded'),
            TextEntry::make('model')->label('Model')->placeholder('Not received yet'),
            TextEntry::make('options.location')
                ->label('Location'),
            TextEntry::make('verification_formats')
                ->label('Supported verification methods')
                ->state(fn (Device $record): string => collect($record->verificationFormats())
                    ->map(fn (string $format): string => Device::VERIFICATION_FORMAT_OPTIONS[$format])
                    ->join(', ')),
            TextEntry::make('last_activity_at')
                ->label('Last connected')
                ->timezone(TenantTimezone::get())
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
                    ->timezone(TenantTimezone::get())
                    ->date('M j, Y')
                    ->description(fn (Device $record): ?string => $record->last_activity_at?->setTimezone(TenantTimezone::get())->format('H:i:s'))
                    ->sortable()
                    ->toggleable(),
                Tables\Columns\TextColumn::make('last_sync_at')
                    ->label('Last Sync')
                    ->timezone(TenantTimezone::get())
                    ->date('M j, Y')
                    ->description(fn (Device $record): ?string => $record->last_sync_at?->setTimezone(TenantTimezone::get())->format('H:i:s'))
                    ->sortable()
                    ->toggleable()
                    ->visibleFrom('md'),
            ])
            ->filters([
                Tables\Filters\SelectFilter::make('connection')
                    ->label('Connection')
                    ->options([
                        'online' => 'Online',
                        'offline' => 'Offline',
                        'waiting' => 'Waiting for connection',
                    ])
                    ->query(function (Builder $query, array $data): Builder {
                        return match ($data['value'] ?? null) {
                            'online' => $query->connected(),
                            'offline' => $query->whereNotNull('last_activity_at')->where(function (Builder $offline): void {
                                $offline->where('last_activity_at', '<', now()->subMinutes(Device::CONNECTION_WINDOW_MINUTES))
                                    ->orWhere('last_activity_at', '>', now());
                            }),
                            'waiting' => $query->whereNull('last_activity_at'),
                            default => $query,
                        };
                    }),
            ])
            ->recordActions([
                ActionGroup::make([
                    ViewAction::make(),
                    ...self::connectionActions(),
                    Action::make('reboot')
                        ->authorize(fn (): bool => DeviceIntegration::usesEbio())
                        ->label('Reboot Device')
                        ->icon('heroicon-o-power')
                        ->requiresConfirmation()
                        ->action(function (Device $record) {
                            app(DeviceCommandService::class)->request($record, 'reboot');
                            Notification::make()
                                ->title('Command Queued')
                                ->body('Reboot command queued.')
                                ->success()
                                ->send();
                        }),
                    Action::make('clearLogs')
                        ->authorize(fn (): bool => DeviceIntegration::usesEbio())
                        ->label('Clear Logs')
                        ->icon('heroicon-o-trash')
                        ->requiresConfirmation()
                        ->color('danger')
                        ->action(function (Device $record) {
                            app(DeviceCommandService::class)->request($record, 'clear_logs');
                            Notification::make()
                                ->title('Command Queued')
                                ->body('Clear logs command queued.')
                                ->success()
                                ->send();
                        }),
                    Action::make('resetTransactionStamp')
                        ->authorize(fn (Device $record): bool => app(DeviceCommandService::class)->available($record, 'reset_transaction_stamp'))
                        ->label('Reset Transaction Stamp')
                        ->icon('heroicon-o-arrow-down-tray')
                        ->requiresConfirmation()
                        ->action(function (Device $record) {
                            app(DeviceCommandService::class)->request($record, 'reset_transaction_stamp');
                            Notification::make()
                                ->title('Command Queued')
                                ->body('Reset transaction stamp command queued.')
                                ->success()
                                ->send();
                        }),
                    Action::make('resetOPStamp')
                        ->authorize(fn (Device $record): bool => app(DeviceCommandService::class)->available($record, 'reset_op_stamp'))
                        ->label('Reset OP Stamp')
                        ->icon('heroicon-o-arrow-path')
                        ->requiresConfirmation()
                        ->action(function (Device $record) {
                            app(DeviceCommandService::class)->request($record, 'reset_op_stamp');
                            Notification::make()
                                ->title('Command Queued')
                                ->body('Reset OP stamp command queued.')
                                ->success()
                                ->send();
                        }),
                    Action::make('unlockDoor')
                        ->authorize(fn (Device $record): bool => app(DeviceCommandService::class)->available($record, 'unlock_door'))
                        ->label('Unlock Door')
                        ->icon('heroicon-o-lock-open')
                        ->requiresConfirmation()
                        ->action(function (Device $record) {
                            app(DeviceCommandService::class)->request($record, 'unlock_door');
                            Notification::make()
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
            RelationManagers\AccessGroupsRelationManager::class,
            RelationManagers\AccessPeriodsRelationManager::class,
        ];
    }

    public static function connectionActions(): array
    {
        $actions = [Action::make('connectionSetup')
            ->label('Connection setup')->icon('heroicon-o-wifi')
            ->authorize(fn (): bool => tenant()?->device_integration === DeviceIntegration::GATEWAY && auth('web')->user()?->has_login)
            ->modalHeading('Connect your device')->modalSubmitAction(false)->modalCancelActionLabel('Done')
            ->modalContent(fn (Device $record) => view('filament.tenant.devices.connection-setup', app(TenantDeviceRegistration::class)->setup($record)))];
        $actions[] = Action::make('repairConnection')->label('Repair connection')->icon('heroicon-o-arrow-path')
            ->visible(fn (Device $record): bool => app(TenantDeviceRegistration::class)->canRepair($record))
            ->authorize(fn (): bool => tenant()?->device_integration === DeviceIntegration::GATEWAY && auth('web')->user()?->has_login)
            ->requiresConfirmation()->modalDescription('Retry this device registration without changing its owner or resetting attendance.')
            ->action(function (Device $record) {
                app(TenantDeviceRegistration::class)->repair($record);
                Notification::make()->title('Connection repair queued')->success()->send();
            });
        $actions[] = Action::make('syncTime')->label('Sync time')->icon('heroicon-o-clock')
            ->visible(fn (Device $record): bool => tenant()?->device_integration === DeviceIntegration::GATEWAY
                && app(DeviceCommandService::class)->available($record, 'sync_time'))
            ->authorize(fn (): bool => (bool) auth('web')->user()?->has_login)
            ->requiresConfirmation()->modalHeading('Sync device time')
            ->modalDescription(fn (Device $record): string => 'Set the device clock to the current time in '.app(GatewayCommands::class)->timezone($record).'. Saved attendance is unchanged.')
            ->action(function (Device $record) {
                app(DeviceCommandService::class)->request($record, 'sync_time');
                Notification::make()->title('Time sync queued')->body('Check Device Commands for the device acknowledgement.')->success()->send();
            });
        $actions[] = Action::make('clearDeviceAttendance')->label('Clear device attendance')->icon('heroicon-o-trash')->color('danger')
            ->visible(fn (Device $record): bool => tenant()?->device_integration === DeviceIntegration::GATEWAY
                && app(DeviceCommandService::class)->available($record, 'clear_logs'))
            ->authorize(fn (): bool => (bool) auth('web')->user()?->has_login)
            ->modalHeading('Clear attendance from this device')
            ->modalDescription('Saved server punches and manual corrections stay unchanged. Attendance that has not reached the server will be lost. Confirm the full upload has finished before continuing.')
            ->modalSubmitActionLabel('Clear device attendance')
            ->schema([
                Radio::make('dialect')->label('Device command')->options([
                    'clear_log' => 'Standard — CLEAR LOG',
                    'delete_attlog' => 'Compatibility — DATA DELETE ATTLOG',
                ])->default('clear_log')->required()->helperText('Use compatibility only after verifying it on this firmware. No automatic fallback is sent.'),
                Checkbox::make('confirmed')->label('I have checked that the device attendance is saved on the server.')->accepted()->required(),
            ])
            ->action(function (Device $record, array $data) {
                validator($data, ['confirmed' => ['accepted'], 'dialect' => ['required', 'in:clear_log,delete_attlog']])->validate();
                app(DeviceCommandService::class)->request($record, 'clear_logs', ['dialect' => $data['dialect']]);
                Notification::make()->title('Device clear queued')->body('Server history is retained. Check Device Commands for the device acknowledgement.')->success()->send();
            });
        foreach (['check' => ['Check connection', 'heroicon-o-signal'], 'info' => ['Refresh device details', 'heroicon-o-information-circle'], 'query_users' => ['Read device users', 'heroicon-o-users']] as $operation => [$label, $icon]) {
            $actions[] = Action::make($operation)->label($label)->icon($icon)
                ->authorize(fn (Device $record): bool => app(DeviceCommandService::class)->available($record, $operation))
                ->action(function (Device $record) use ($operation) {
                    app(DeviceCommandService::class)->request($record, $operation);
                    Notification::make()->title('Request sent')->body('Track its progress in Device actions. Offline devices respond when they reconnect.')->success()->send();
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
