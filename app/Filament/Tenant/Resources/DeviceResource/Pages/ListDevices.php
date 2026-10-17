<?php

namespace App\Filament\Tenant\Resources\DeviceResource\Pages;

use Filament\Actions;
use Filament\Resources\Pages\ListRecords;
use App\Filament\Tenant\Resources\DeviceResource;

class ListDevices extends ListRecords
{
    protected static string $resource = DeviceResource::class;

    protected function getHeaderActions(): array  {
        return [
            Actions\Action::make('addConnectedDevice')
                // Registration coordinates central and tenant storage; a panel
                // transaction cannot span the tenant connection switches.
                ->databaseTransaction(false)
                ->label('Add device')
                ->icon('heroicon-o-plus')
                ->authorize(fn (): bool => tenant()?->device_integration === \App\Services\Devices\DeviceIntegration::GATEWAY && auth('web')->user()?->has_login)
                ->modalHeading('Add attendance device')
                ->modalDescription('Add the device once. The connection and available commands are set up automatically.')
                ->schema([
                    \Filament\Forms\Components\TextInput::make('name')->label('Device name')->required()->maxLength(255),
                    \Filament\Forms\Components\TextInput::make('serial_number')->label('Serial number')->required()->regex('/^[A-Za-z0-9_-]{1,64}$/')->helperText('Find this in the device’s information menu.'),
                    \Filament\Forms\Components\TextInput::make('gateway_source_cidr')
                        ->label('Office public IP address')
                        ->helperText('Needed only for the first device at this office. It protects every device on this company network and is reused automatically.')
                        ->visible(fn (): bool => blank(tenant()?->gateway_source_cidr)),
                    \Filament\Forms\Components\TextInput::make('device_ip')->label('Device IP address')->ip()->helperText('Optional: the device’s local network address, kept for reference.'),
                    \Filament\Forms\Components\TextInput::make('location')->label('Location')->maxLength(255),
                    \Filament\Forms\Components\Select::make('timezone')->label('Time zone')->options(array_combine(timezone_identifiers_list(), timezone_identifiers_list()))->searchable()->default('Asia/Kolkata')->required(),
                ])
                ->modalSubmitActionLabel('Add device')
                ->action(function (array $data) {
                    $device = app(\App\Services\Devices\TenantDeviceRegistration::class)->register($data);
                    \Filament\Notifications\Notification::make()->title('Device added')->body('Its gateway binding is being connected automatically. Configure the device to use the company gateway address, then it will appear online.')->success()->send();
                    $this->redirect(DeviceResource::getUrl('index'));
                }),
            Actions\Action::make('syncEbioDevices')
                ->authorize(fn (): bool => \App\Services\Devices\DeviceIntegration::usesEbio())
                ->label('Sync devices')
                ->icon('heroicon-o-arrow-path')
                ->color('warning')
                ->requiresConfirmation()
                ->modalHeading('Sync devices')
                ->action(function () {
                    try {
                        $service = new \App\Services\EbioSoapService();
                        $result = $service->syncDevices(tenant());
                        
                        \Filament\Notifications\Notification::make()
                            ->title('Sync Complete')
                            ->body("Successfully synced {$result['synced']} devices.")
                            ->success()
                            ->send();
                    } catch (\Exception $e) {
                        \Filament\Notifications\Notification::make()
                            ->title('Sync Failed')
                            ->body('The device action could not be completed. Contact your administrator.')
                            ->danger()
                            ->send();
                    }
                }),
            Actions\Action::make('runCommand')
                ->authorize(fn (): bool => in_array(tenant()?->device_integration, [\App\Services\Devices\DeviceIntegration::EBIO, \App\Services\Devices\DeviceIntegration::GATEWAY], true))
                ->label('Run Command')
                ->icon('heroicon-o-command-line')
                ->color('primary')
                ->form([
                    \Filament\Forms\Components\Select::make('device_id')
                        ->label('Select Device')
                        ->options(function () {
                            return \App\Models\Device::all()->mapWithKeys(function ($d) {
                                return [$d->id => $d->name ?: $d->serial_number];
                            });
                        })
                        ->required()
                        ->searchable()
                        ->live()
                        ->afterStateUpdated(fn (callable $set) => $set('command', null))
                        ->default(fn () => \App\Models\Device::count() === 1 ? \App\Models\Device::first()->id : null),
                    \Filament\Forms\Components\Select::make('command')
                        ->label('Select Command')
                        ->live()
                        ->options(function (callable $get) {
                            $deviceId = $get('device_id');
                            $device = $deviceId ? \App\Models\Device::find($deviceId) : null;
                            if ($device && tenant()?->device_integration === \App\Services\Devices\DeviceIntegration::GATEWAY) {
                                return collect(\App\Services\Devices\Gateway\GatewayCommands::OPERATIONS)
                                    ->filter(fn ($label, $operation) => app(\App\Services\Devices\DeviceCommandService::class)->available($device, $operation))
                                    ->all();
                            }
                            return [
                                'reboot' => 'Reboot Device',
                                'clear_logs' => 'CRITICAL: Clear Attendance Logs',
                                'reset_transaction_stamp' => 'Reset Transaction Stamp',
                                'reset_op_stamp' => 'Reset OP Stamp',
                                'unlock_door' => 'Unlock Door',
                            ];
                        })
                        ->required(),
                    \Filament\Forms\Components\TextInput::make('confirm')
                        ->label("Type 'CONFIRM' to execute this command")

                        ->required()
                        ->rule(function () {
                            return function (string $attribute, $value, \Closure $fail) {
                                if ($value !== 'CONFIRM') {
                                    $fail("You must type 'CONFIRM' exactly (all caps) to execute this command.");
                                }
                            };
                        })
                        ->hidden(fn ($get) => !in_array($get('command'), ['clear_logs', 'reboot'])),
                ])
                ->action(function (array $data) {
                    $device = \App\Models\Device::find($data['device_id']);
                    if (!$device) return;

                    app(\App\Services\Devices\DeviceCommandService::class)->request($device, $data['command']);
                    
                    \Filament\Notifications\Notification::make()
                        ->title('Command Queued')
                        ->body("The '{$data['command']}' command has been queued for sync.")
                        ->success()
                        ->send();
                }),
            Actions\CreateAction::make()
                ->authorize(fn (): bool => \App\Services\Devices\DeviceIntegration::usesEbio())
                ->label('Add Device')
                ->icon('heroicon-o-plus')
                ->modalHeading('Add device')
                ->form([
                    \Filament\Schemas\Components\Grid::make(2)->schema([
                        \Filament\Forms\Components\TextInput::make('serial_number')
                            ->required()
                            ->label('Serial Number')
                            ->columnSpan('full'),
                        \Filament\Forms\Components\TextInput::make('name')
                            ->required()
                            ->label('Device Name'),
                        \Filament\Forms\Components\TextInput::make('location')
                            ->required()
                            ->label('Location'),
                        \Filament\Forms\Components\Select::make('direction')
                            ->options(['IN' => 'IN', 'OUT' => 'OUT', 'OTHER' => 'OTHER'])
                            ->required()
                            ->label('Direction'),
                        \Filament\Forms\Components\TextInput::make('device_type')
                            ->default('Attendance')
                            ->required()
                            ->label('Device Type'),
                        \Filament\Forms\Components\TextInput::make('time_zone')
                            ->default('Asia/Kolkata')
                            ->required()
                            ->label('Time Zone'),
                        \Filament\Forms\Components\TextInput::make('activation_code')
                            ->default('0')
                            ->label('Activation Code'),
                        \Filament\Forms\Components\Select::make('is_attendance_device')
                            ->options(['true' => 'Yes', 'false' => 'No'])
                            ->default('true')
                            ->required()
                            ->label('Is Attendance Device'),
                    ])
                ])
                ->using(function (array $data, string $model): \Illuminate\Database\Eloquent\Model {
                    $service = new \App\Services\EbioSoapService();
                    
                    try {
                        $success = $service->addDevice(tenant(), $data);
                        if (!$success) {
                            throw new \Exception("eBioServer API rejected the device addition.");
                        }
                    } catch (\Exception $e) {
                        \Filament\Notifications\Notification::make()
                            ->title('Add Device Failed')
                            ->body('The device action could not be completed. Contact your administrator.')
                            ->danger()
                            ->send();
                        
                        throw \Illuminate\Validation\ValidationException::withMessages([
                            'serial_number' => 'The device could not be added. Contact your administrator.'
                        ]);
                    }

                    return $model::create([
                        'serial_number' => $data['serial_number'],
                        'name' => $data['name'],
                        'status' => 'offline',
                        'options' => [
                            'location' => $data['location'],
                            'direction' => $data['direction'],
                            'type' => $data['device_type'],
                            'timezone' => $data['time_zone'],
                        ]
                    ]);
                }),
        ];
    }
}
