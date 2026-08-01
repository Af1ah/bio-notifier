<?php

namespace App\Filament\Tenant\Resources;

use Filament\Actions\Action;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Actions\ViewAction;
use Filament\Forms\Components\Select;
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
        return false;
    }

    public static function canDelete(\Illuminate\Database\Eloquent\Model $record): bool
    {
        return false;
    }

    public static function infolist(Schema $schema): Schema
    {
        return $schema->components([
            \Filament\Infolists\Components\TextEntry::make('serial_number'),
            \Filament\Infolists\Components\TextEntry::make('name'),
            \Filament\Infolists\Components\TextEntry::make('options.location')
                ->label('Location'),
            \Filament\Infolists\Components\TextEntry::make('last_activity_at')
                ->label('Last Ping')
                ->dateTime(),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
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
                    ->getStateUsing(fn (Device $record): string => $record->isOnline() ? 'online' : 'offline')
                    ->color(fn (string $state): string => match ($state) {
                        'online' => 'success',
                        'offline' => 'danger',
                        default => 'warning',
                    })
                    ->visibleFrom('md'),
                Tables\Columns\TextColumn::make('last_activity_at')
                    ->label('Last Ping')
                    ->date('M j, Y')
                    ->description(fn (Device $record): ?string => $record->last_activity_at?->format('H:i:s'))
                    ->sortable()
                    ->toggleable(),
                Tables\Columns\TextColumn::make('last_sync_at')
                    ->label('Last Sync')
                    ->date('M j, Y')
                    ->description(fn (Device $record): ?string => $record->last_sync_at?->format('H:i:s'))
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
                    \Filament\Actions\Action::make('reboot')
                        ->label('Reboot Device')
                        ->icon('heroicon-o-power')
                        ->requiresConfirmation()
                        ->action(function (Device $record) {
                            $command = \App\Models\DeviceCommand::create([
                                'device_id' => $record->id,
                                'command_type' => 'reboot',
                                'command_content' => 'eBioServer SOAP Command: reboot',
                                'status' => 'pending',
                            ]);
                            \App\Jobs\EbioDeviceCommandJob::dispatch(tenancy()->tenant, $record->serial_number, 'reboot', $command->id);
                            \Filament\Notifications\Notification::make()
                                ->title('Command Queued')
                                ->body('Reboot command queued.')
                                ->success()
                                ->send();
                        }),
                    \Filament\Actions\Action::make('clearLogs')
                        ->label('Clear Logs')
                        ->icon('heroicon-o-trash')
                        ->requiresConfirmation()
                        ->color('danger')
                        ->action(function (Device $record) {
                            $command = \App\Models\DeviceCommand::create([
                                'device_id' => $record->id,
                                'command_type' => 'clear_logs',
                                'command_content' => 'eBioServer SOAP Command: clear_logs',
                                'status' => 'pending',
                            ]);
                            \App\Jobs\EbioDeviceCommandJob::dispatch(tenancy()->tenant, $record->serial_number, 'clear_logs', $command->id);
                            \Filament\Notifications\Notification::make()
                                ->title('Command Queued')
                                ->body('Clear logs command queued.')
                                ->success()
                                ->send();
                        }),
                    \Filament\Actions\Action::make('resetTransactionStamp')
                        ->label('Reset Transaction Stamp')
                        ->icon('heroicon-o-arrow-down-tray')
                        ->requiresConfirmation()
                        ->action(function (Device $record) {
                            $command = \App\Models\DeviceCommand::create([
                                'device_id' => $record->id,
                                'command_type' => 'reset_transaction_stamp',
                                'command_content' => 'eBioServer SOAP Command: reset_transaction_stamp',
                                'status' => 'pending',
                            ]);
                            \App\Jobs\EbioDeviceCommandJob::dispatch(tenancy()->tenant, $record->serial_number, 'reset_transaction_stamp', $command->id);
                            \Filament\Notifications\Notification::make()
                                ->title('Command Queued')
                                ->body('Reset transaction stamp command queued.')
                                ->success()
                                ->send();
                        }),
                    \Filament\Actions\Action::make('resetOPStamp')
                        ->label('Reset OP Stamp')
                        ->icon('heroicon-o-arrow-path')
                        ->requiresConfirmation()
                        ->action(function (Device $record) {
                            $command = \App\Models\DeviceCommand::create([
                                'device_id' => $record->id,
                                'command_type' => 'reset_op_stamp',
                                'command_content' => 'eBioServer SOAP Command: reset_op_stamp',
                                'status' => 'pending',
                            ]);
                            \App\Jobs\EbioDeviceCommandJob::dispatch(tenancy()->tenant, $record->serial_number, 'reset_op_stamp', $command->id);
                            \Filament\Notifications\Notification::make()
                                ->title('Command Queued')
                                ->body('Reset OP stamp command queued.')
                                ->success()
                                ->send();
                        }),
                    \Filament\Actions\Action::make('unlockDoor')
                        ->label('Unlock Door')
                        ->icon('heroicon-o-lock-open')
                        ->requiresConfirmation()
                        ->action(function (Device $record) {
                            $command = \App\Models\DeviceCommand::create([
                                'device_id' => $record->id,
                                'command_type' => 'unlock_door',
                                'command_content' => 'eBioServer SOAP Command: unlock_door',
                                'status' => 'pending',
                            ]);
                            \App\Jobs\EbioDeviceCommandJob::dispatch(tenancy()->tenant, $record->serial_number, 'unlock_door', $command->id);
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
        ];
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListDevices::route('/'),
            'view' => Pages\ViewDevice::route('/{record}'),
        ];
    }
}
