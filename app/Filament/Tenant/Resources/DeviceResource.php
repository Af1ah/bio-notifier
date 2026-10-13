<?php

namespace App\Filament\Tenant\Resources;

use App\Filament\Tenant\Resources\DeviceResource\Pages;
use App\Filament\Tenant\Resources\DeviceResource\RelationManagers;
use App\Models\Device;
use App\Services\Attendance\DeviceCommandBuilder;
use Filament\Actions\Action;
use Filament\Actions\ActionGroup;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteAction;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Actions\ViewAction;
use Filament\Forms\Components\CheckboxList;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use App\Services\Attendance\MatrixDeviceService;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Support\Facades\Http;
use Shaykhnazar\HikvisionIsapi\Facades\Hikvision;
use Shaykhnazar\HikvisionIsapi\Services\DeviceService;

class DeviceResource extends Resource
{
    protected static ?string $model = Device::class;

    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-cpu-chip';

    protected static ?int $navigationSort = 1;

    protected static \UnitEnum|string|null $navigationGroup = 'Device Management';

    //

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            Section::make('Device Information')
                ->schema([
                    Select::make('vendor')
                        ->options([
                            'zkteco' => 'ZKTeco (ADMS)',
                            'hikvision' => 'Hikvision (ISAPI)',
                            'matrix' => 'Matrix (COSEC)',
                        ])
                        ->default('zkteco')
                        ->live()
                        ->afterStateUpdated(fn ($state, callable $set) => in_array($state, ['hikvision', 'matrix']) ? $set('serial_number', null) : null),
                    TextInput::make('serial_number')
                        ->label('Serial Number / ID')
                        ->nullable()
                        ->required(fn ($get) => $get('vendor') === 'zkteco')
                        ->unique(ignoreRecord: true)
                        ->maxLength(100)
                        ->helperText(fn ($get) => $get('vendor') === 'matrix' ? 'Optional for Matrix COSEC (used as local identifier).' : null),
                    Select::make('connection_mode')
                        ->label('Connection')->options(['direct'=>'Direct network','remote'=>'Remote site bridge'])->default('direct')->live()
                        ->visible(fn($get)=>$get('vendor')==='matrix'),
                    Select::make('site_bridge_id')->label('Site bridge')
                        ->options(fn()=>\App\Models\SiteBridge::where('tenant_id',(string)tenant('id'))->whereNull('revoked_at')->whereNotNull('public_key')->pluck('name','id'))
                        ->required(fn($get)=>$get('connection_mode')==='remote')
                        ->visible(fn($get)=>$get('vendor')==='matrix' && $get('connection_mode')==='remote'),
                    TextInput::make('remote_web_host')->label('Browser hostname')->disabled()->dehydrated(false)
                        ->afterStateHydrated(function ($component, $record) { if ($record?->exists) $component->state(app(\App\Services\Bridge\BridgeGateway::class)->webHost($record)); })
                        ->visible(fn($get,$record)=>$record?->exists && $get('vendor')==='matrix' && $get('connection_mode')==='remote')
                        ->helperText('Uses the existing bridge domain. Open one Matrix device per browser session; use a separate private window for another device.'),
                    TextInput::make('ip_address')
                        ->label('IP Address')
                        ->required(fn ($get) => in_array($get('vendor'), ['hikvision', 'matrix'])),
                    TextInput::make('username')
                        ->visible(fn ($get) => in_array($get('vendor'), ['hikvision', 'matrix']))
                        ->required(fn ($get) => in_array($get('vendor'), ['hikvision', 'matrix'])),
                    TextInput::make('password')
                        ->password()
                        ->visible(fn ($get) => in_array($get('vendor'), ['hikvision', 'matrix']))
                        ->required(fn ($get) => in_array($get('vendor'), ['hikvision', 'matrix'])),
                    TextInput::make('port')
                        ->numeric()
                        ->default(80)
                        ->visible(fn ($get) => in_array($get('vendor'), ['hikvision', 'matrix'])),
                    Select::make('protocol')
                        ->options(['http' => 'HTTP', 'https' => 'HTTPS'])
                        ->default('http')
                        ->visible(fn ($get) => in_array($get('vendor'), ['hikvision', 'matrix'])),
                    TextInput::make('name')
                        ->maxLength(255),
                    Select::make('branch_id')
                        ->relationship('branch', 'name')
                        ->searchable()
                        ->preload(),
                    TextInput::make('model')
                        ->disabled(fn ($get) => in_array($get('vendor'), ['hikvision', 'matrix'])),
                    TextInput::make('firmware_version')
                        ->disabled(),
                    TextInput::make('push_version')
                        ->disabled(fn ($get) => in_array($get('vendor'), ['hikvision', 'matrix'])),
                    Select::make('status')
                        ->options([
                            'online' => 'Online',
                            'offline' => 'Offline',
                            'unknown' => 'Unknown',
                        ])
                        ->default('unknown'),
                    Select::make('punch_behavior')
                        ->options([
                            'device_state' => 'Device State (Default)',
                            'always_in' => 'Always In',
                            'always_out' => 'Always Out',
                            'auto' => 'Auto (Alternating)',
                        ])
                        ->default('device_state')
                        ->helperText('Determines if logs from this device are Check-In, Check-Out, or handled automatically.'),
                    CheckboxList::make('options.enrollment_methods')
                        ->label('Supported Enrollment Methods')
                        ->options([
                            'face' => 'Face Recognition',
                            'finger' => 'Fingerprint',
                            'card' => 'RFID Card',
                            'special_card' => 'Special Function Card',
                        ])
                        ->columns(2)
                        ->default(fn ($get) => $get('vendor') === 'matrix' ? ['face', 'card', 'special_card'] : ['finger', 'card'])
                        ->columnSpanFull()
                        ->helperText('Select the credential and biometric types this physical device supports for user enrollment.'),
                ])
                ->columns(2)
                ->columnSpanFull(),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('name')
                    ->label('Device Name')
                    ->searchable()
                    ->sortable()
                    ->weight('bold')
                    ->default('Unnamed Device'),
                Tables\Columns\TextColumn::make('serial_number')
                    ->label('Serial / ID')
                    ->searchable()
                    ->sortable()
                    ->copyable()
                    ->copyMessage('Serial number copied')
                    ->fontFamily('mono'),
                Tables\Columns\TextColumn::make('vendor')
                    ->label('Vendor')
                    ->badge()
                    ->formatStateUsing(fn (?string $state): string => match (strtolower((string) $state)) {
                        'matrix' => 'Matrix COSEC',
                        'hikvision' => 'Hikvision',
                        'zkteco' => 'ZKTeco ADMS',
                        default => ucfirst((string) $state) ?: 'ZKTeco',
                    })
                    ->color(fn (?string $state): string => match (strtolower((string) $state)) {
                        'matrix' => 'info',
                        'hikvision' => 'warning',
                        'zkteco' => 'primary',
                        default => 'gray',
                    }),
                Tables\Columns\TextColumn::make('ip_address')
                    ->label('IP Address')
                    ->formatStateUsing(fn (Device $record) => $record->ip_address ? ($record->port && $record->port != 80 ? "{$record->ip_address}:{$record->port}" : $record->ip_address) : '--')
                    ->searchable()
                    ->fontFamily('mono')
                    ->toggleable(),
                Tables\Columns\TextColumn::make('branch.name')
                    ->label('Branch')
                    ->sortable()
                    ->searchable()
                    ->default('Not Assigned')
                    ->toggleable(),
                Tables\Columns\TextColumn::make('status')
                    ->badge()
                    ->getStateUsing(fn (Device $record): string => $record->isOnline() ? 'online' : 'offline')
                    ->icon(fn (string $state): string => match ($state) {
                        'online' => 'heroicon-o-check-circle',
                        'offline' => 'heroicon-o-x-circle',
                        default => 'heroicon-o-question-mark-circle',
                    })
                    ->color(fn (string $state): string => match ($state) {
                        'online' => 'success',
                        'offline' => 'danger',
                        default => 'warning',
                    }),
                Tables\Columns\TextColumn::make('model')
                    ->label('Model')
                    ->searchable()
                    ->toggleable(isToggledHiddenByDefault: true),
                Tables\Columns\TextColumn::make('last_activity_at')
                    ->label('Last Activity')
                    ->since()
                    ->dateTimeTooltip()
                    ->sortable(),
                Tables\Columns\TextColumn::make('attendance_logs_count')
                    ->counts('attendanceLogs')
                    ->label('Logs')
                    ->badge()
                    ->color('gray')
                    ->sortable(),
            ])
            ->filters([
                Tables\Filters\SelectFilter::make('vendor')
                    ->label('Vendor')
                    ->options([
                        'zkteco' => 'ZKTeco',
                        'hikvision' => 'Hikvision',
                        'matrix' => 'Matrix',
                    ]),
                Tables\Filters\SelectFilter::make('status')
                    ->options([
                        'online' => 'Online',
                        'offline' => 'Offline',
                    ]),
                Tables\Filters\SelectFilter::make('branch_id')
                    ->relationship('branch', 'name')
                    ->getOptionLabelFromRecordUsing(fn ($record) => $record->display_name)
                    ->label('Branch'),
            ])
            ->recordActions([
                Action::make('remote_web')->label('Open remote interface')->icon('heroicon-o-globe-alt')
                    ->visible(fn($record)=>$record->vendor==='matrix' && $record->connection_mode==='remote')
                    ->url(fn($record)=>url('/'.tenant('shortname').'/bridge-device/'.$record->getKey().'/open'))->openUrlInNewTab(),
                ActionGroup::make([
                    ViewAction::make(),
                    EditAction::make(),
                    Action::make('checkStatus')
                        ->label(fn (Device $record) => match ($record->vendor) {
                            'matrix' => 'Check Matrix API Status',
                            'hikvision' => 'Check ISAPI Status',
                            default => 'Check ADMS Connection',
                        })
                        ->icon('heroicon-o-signal')
                        ->color('success')
                        ->action(function (Device $record) {
                            if ($record->vendor === 'matrix') {
                                $result = app(\App\Services\Attendance\MatrixDeviceService::class)->checkConnection($record);

                                if ($result['success']) {
                                    $record->update([
                                        'status' => 'online',
                                        'model' => $result['model'] ?? ($record->model ?: 'Matrix COSEC'),
                                        'last_activity_at' => now(),
                                    ]);

                                    if (!empty($result['enrollment_methods'])) {
                                        $options = $record->options ?? [];
                                        $options['enrollment_methods'] = $result['enrollment_methods'];
                                        $record->update(['options' => $options]);
                                    }

                                    Notification::make()
                                        ->title('Device Online')
                                        ->body($result['message'])
                                        ->success()
                                        ->send();
                                } else {
                                    $record->update(['status' => 'offline']);
                                    Notification::make()
                                        ->title('Device Connection Failed')
                                        ->body($result['message'])
                                        ->danger()
                                        ->persistent()
                                        ->send();
                                }
                            } elseif ($record->vendor === 'hikvision') {
                                try {
                                    Hikvision::registerDevice('device_'.$record->id, [
                                        'ip' => $record->ip_address,
                                        'port' => $record->port ?? 80,
                                        'username' => $record->username,
                                        'password' => $record->password,
                                        'protocol' => $record->protocol ?? 'http',
                                        'timeout' => 5,
                                        'verify_ssl' => false,
                                    ]);
                                    $client = Hikvision::device('device_'.$record->id);
                                    $deviceService = new DeviceService($client);

                                    if ($deviceService->isOnline()) {
                                        $info = $deviceService->getInfo();
                                        $model = $info['DeviceInfo']['model'] ?? 'Unknown';
                                        $fw = $info['DeviceInfo']['firmwareVersion'] ?? 'Unknown';

                                        $record->update([
                                            'status' => 'online',
                                            'model' => $model,
                                            'firmware_version' => $fw,
                                            'last_activity_at' => now(),
                                        ]);

                                        Notification::make()
                                            ->title('Device Online')
                                            ->body("Model: {$model}\nFirmware: {$fw}")
                                            ->success()
                                            ->send();
                                    } else {
                                        $record->update(['status' => 'offline']);
                                        Notification::make()
                                            ->title('Device Offline')
                                            ->body('Device is not responding to ISAPI requests.')
                                            ->warning()
                                            ->send();
                                    }
                                } catch (\Exception $e) {
                                    $record->update(['status' => 'offline']);
                                    Notification::make()
                                        ->title('Connection Failed')
                                        ->body($e->getMessage())
                                        ->danger()
                                        ->send();
                                }
                            } else {
                                app(DeviceCommandBuilder::class)->checkConnection($record);
                                Notification::make()->title('Command Queued')->body('Check connection command queued successfully.')->success()->send();
                            }
                        }),
                    Action::make('syncTime')
                        ->label('Sync Time')
                        ->icon('heroicon-o-clock')
                        ->color('primary')
                        ->requiresConfirmation()
                        ->modalHeading('Sync Device Time')
                        ->modalDescription('Queue a command to sync the device time with the server time.')
                        ->action(function (Device $record) {
                            app(DeviceCommandBuilder::class)->syncTime($record);
                            Notification::make()->title('Command Queued')->body('Time sync command queued successfully.')->success()->send();
                        }),
                    Action::make('unlockDoor')
                        ->label('Unlock Door')
                        ->icon('heroicon-o-lock-open')
                        ->color('success')
                        ->requiresConfirmation()
                        ->modalHeading('Unlock Door')
                        ->modalDescription('Send an unlock command to the door controller.')
                        ->action(function (Device $record) {
                            $cmd = app(DeviceCommandBuilder::class)->unlockDoor($record);
                            Notification::make()
                                ->title($cmd->status === 'failed' ? 'Unlock Failed' : 'Door Unlocked')
                                ->body($cmd->response ?: 'Unlock command processed.')
                                ->status($cmd->status === 'failed' ? 'danger' : 'success')
                                ->send();
                        }),
                    Action::make('lockDoor')
                        ->label('Lock Door')
                        ->icon('heroicon-o-lock-closed')
                        ->color('danger')
                        ->requiresConfirmation()
                        ->modalHeading('Lock Door')
                        ->modalDescription('Send a lock command to the door controller.')
                        ->action(function (Device $record) {
                            $cmd = app(DeviceCommandBuilder::class)->lockDoor($record);
                            Notification::make()
                                ->title($cmd->status === 'failed' ? 'Lock Failed' : 'Door Locked')
                                ->body($cmd->response ?: 'Lock command processed.')
                                ->status($cmd->status === 'failed' ? 'danger' : 'success')
                                ->send();
                        }),
                    Action::make('normalizeDoor')
                        ->label('Set to Normal State')
                        ->icon('heroicon-o-shield-check')
                        ->color('info')
                        ->requiresConfirmation()
                        ->modalHeading('Set Door to Normal State')
                        ->modalDescription('Return the door to standard access evaluation mode.')
                        ->action(function (Device $record) {
                            $cmd = app(DeviceCommandBuilder::class)->normalizeDoor($record);
                            Notification::make()
                                ->title($cmd->status === 'failed' ? 'Command Failed' : 'Door Normalized')
                                ->body($cmd->response ?: 'Door returned to normal mode.')
                                ->status($cmd->status === 'failed' ? 'danger' : 'success')
                                ->send();
                        }),
                    Action::make('enableEnrollment')
                        ->label('Enable Device Enrollment')
                        ->icon('heroicon-o-finger-print')
                        ->color('info')
                        ->requiresConfirmation()
                        ->modalHeading('Enable Enrollment on Device')
                        ->modalDescription('Command the Matrix device to enable on-device biometric enrollment mode (enroll-on-device=1).')
                        ->visible(fn (Device $record) => $record->vendor === 'matrix')
                        ->action(function (Device $record) {
                            $cmd = app(DeviceCommandBuilder::class)->enableEnrollment($record);
                            Notification::make()
                                ->title($cmd->status === 'failed' ? 'Failed to Enable Enrollment' : 'Enrollment Enabled')
                                ->body($cmd->response ?: 'Enrollment mode activated on Matrix device.')
                                ->status($cmd->status === 'failed' ? 'danger' : 'success')
                                ->send();
                        }),
                    \App\Filament\Tenant\Resources\DeviceResource\Actions\ConfigureInternalReader::make(),
                    Action::make('configureReader')
                        ->label('Configure External / Exit Reader')
                        ->icon('heroicon-o-arrow-right-on-rectangle')
                        ->color('warning')
                        ->visible(fn (Device $record) => $record->vendor === 'matrix')
                        ->mountUsing(function ($form, Device $record) {
                            $matrixSvc = app(MatrixDeviceService::class);
                            $cfg = $matrixSvc->getReaderConfig($record);
                            $reader3 = $cfg['data']['reader3'] ?? '1';
                            $entryExit = $cfg['data']['reader_entry_exit_mode'] ?? '1';
                            $accessMode = $cfg['data']['reader_access_mode'] ?? '6';

                            $form->fill([
                                'reader3' => $reader3,
                                'reader_entry_exit_mode' => $entryExit,
                                'reader_access_mode' => $accessMode,
                            ]);
                        })
                        ->form([
                            Select::make('reader3')
                                ->label('External Reader Type')
                                ->options([
                                    '0' => '0 - None (Disabled)',
                                    '1' => '1 - EM Proximity Reader (COSEC PATH RDCE)',
                                    '2' => '2 - HID Prox Reader',
                                    '3' => '3 - MiFare Reader (COSEC PATH RDCM)',
                                    '4' => '4 - HID iCLASS-U Reader',
                                    '5' => '5 - Finger Reader (COSEC PATH RDFE)',
                                    '6' => '6 - HID iCLASS-W Reader',
                                    '7' => '7 - UHF Reader',
                                    '8' => '8 - Combo Exit Reader',
                                    '9' => '9 - MiFare-W Reader',
                                ])
                                ->helperText('For Matrix COSEC PATH RDCE, select Option 1.')
                                ->required(),
                            Select::make('reader_entry_exit_mode')
                                ->label('Reader Function / Mode')
                                ->options([
                                    '1' => 'Exit Reader',
                                    '0' => 'Entry Reader',
                                ])
                                ->default('1')
                                ->required(),
                            Select::make('reader_access_mode')
                                ->label('Access Authentication Mode')
                                ->options([
                                    '6' => 'Any (Card or Biometric)',
                                    '0' => 'Card Only',
                                    '1' => 'Fingerprint Only',
                                    '4' => 'Card + Fingerprint',
                                    '12' => 'Fingerprint then Card',
                                ])
                                ->default('6')
                                ->required(),
                        ])
                        ->action(function (Device $record, array $data) {
                            $matrixSvc = app(MatrixDeviceService::class);
                            $res = $matrixSvc->setReaderConfig($record, [
                                'reader3' => (int) $data['reader3'],
                                'reader-entry-exit-mode' => (int) $data['reader_entry_exit_mode'],
                                'reader-access-mode' => (int) $data['reader_access_mode'],
                            ]);

                            if ($res['success']) {
                                Notification::make()
                                    ->title('External Reader Configured')
                                    ->body("External reader successfully set to type {$data['reader3']} (" . ($data['reader_entry_exit_mode'] === '1' ? 'Exit' : 'Entry') . ").")
                                    ->success()
                                    ->send();
                            } else {
                                Notification::make()
                                    ->title('Failed to Configure Reader')
                                    ->body($res['message'])
                                    ->danger()
                                    ->persistent()
                                    ->send();
                            }
                        }),
                    Action::make('viewDeviceLogs')
                        ->label('View Device Event Logs')
                        ->icon('heroicon-o-clipboard-document-list')
                        ->color('gray')
                        ->visible(fn (Device $record) => $record->vendor === 'matrix')
                        ->modalHeading('Recent Device Event Logs (Hardware Logs)')
                        ->modalSubmitAction(false)
                        ->modalCancelActionLabel('Close')
                        ->action(function () {})
                        ->form(function (Device $record) {
                            $matrixSvc = app(MatrixDeviceService::class);
                            $countRes = $matrixSvc->getEventCount($record);
                            $lines = [];

                            if (! $countRes['success']) {
                                $lines[] = 'Unable to retrieve device event count: ' . $countRes['message'];

                                return [
                                    Textarea::make('logs')
                                        ->label('Recent Device Events (Directly from Device Hardware)')
                                        ->rows(14)
                                        ->default(implode("\n", $lines))
                                        ->disabled(),
                                ];
                            }

                            $seq = max(1, $countRes['sequence'] - 15);
                            $logRes = $matrixSvc->getEventLogs($record, $seq, 20, $countRes['rollover']);
                            if (!empty($logRes['events'])) {
                                foreach (array_reverse($logRes['events']) as $ev) {
                                    $eventName = match ($ev['event_id']) {
                                        '101' => 'User Allowed (Access Granted)',
                                        '151', '152', '153', '154', '163', '164' => 'User Access Denied',
                                        '402' => 'Login / Web Access Event',
                                        '405' => 'Biometric / Card Enrollment',
                                        '409' => 'Credentials Deleted',
                                        '457' => 'System Configuration Defaulted',
                                        default => "Event ID: {$ev['event_id']}",
                                    };
                                    $lines[] = "[{$ev['date']} {$ev['time']}] #{$ev['seq']} - {$eventName} | User: " . ($ev['detail_1'] ?: 'None') . " | Detail: {$ev['detail_2']}/{$ev['detail_3']}";
                                }
                            } else {
                                $lines[] = $logRes['success']
                                    ? 'No event records retrieved from device.'
                                    : 'Unable to retrieve device events: ' . $logRes['message'];
                            }

                            return [
                                Textarea::make('logs')
                                    ->label('Recent Device Events (Directly from Device Hardware)')
                                    ->rows(14)
                                    ->default(implode("\n", $lines))
                                    ->disabled(),
                            ];
                        }),
                    Action::make('getInfo')
                        ->label('Get Device Info')
                        ->icon('heroicon-o-information-circle')
                        ->color('info')
                        ->requiresConfirmation()
                        ->modalHeading('Get Device Info')
                        ->modalDescription('Send a command to get device information.')
                        ->action(fn (Device $record) => app(DeviceCommandBuilder::class)->info($record)),
                    Action::make('reboot')
                        ->label('Reboot Device')
                        ->icon('heroicon-o-arrow-path')
                        ->color('warning')
                        ->requiresConfirmation()
                        ->modalHeading('Reboot Device')
                        ->modalDescription('Are you sure you want to reboot this device?')
                        ->visible(fn (Device $record) => $record->vendor !== 'matrix')
                        ->action(fn (Device $record) => app(DeviceCommandBuilder::class)->reboot($record)),
                    Action::make('clearLogs')
                        ->label('Clear Logs')
                        ->icon('heroicon-o-trash')
                        ->color('danger')
                        ->requiresConfirmation()
                        ->modalHeading('Clear Device Logs')
                        ->modalDescription('Are you sure you want to clear all attendance logs on this device?')
                        ->action(fn (Device $record) => app(DeviceCommandBuilder::class)->clearAttendanceLogs($record)),
                    DeleteAction::make(),
                ])
                ->icon('heroicon-m-ellipsis-vertical')
                ->tooltip('Actions'),
            ])
            ->bulkActions([
                BulkActionGroup::make([
                    DeleteBulkAction::make(),
                ]),
            ]);
    }

    public static function getRelations(): array
    {
        return [
            RelationManagers\AttendanceLogsRelationManager::class,
            RelationManagers\CommandsRelationManager::class,
        ];
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListDevices::route('/'),
            'create' => Pages\CreateDevice::route('/create'),
            'view' => Pages\ViewDevice::route('/{record}'),
            'edit' => Pages\EditDevice::route('/{record}/edit'),
        ];
    }
}
