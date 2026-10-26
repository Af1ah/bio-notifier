<?php

namespace App\Filament\Tenant\Resources\DeviceResource\Pages;

use App\Filament\Tenant\Resources\DeviceResource;
use App\Models\Device;
use App\Services\Devices\DeviceCommandService;
use App\Services\Devices\DeviceIntegration;
use App\Services\Devices\Gateway\GatewayCommands;
use App\Services\Devices\TenantDeviceRegistration;
use App\Services\EbioSoapService;
use App\Services\Support\TenantTimezone;
use Filament\Actions;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ListRecords;
use Filament\Schemas\Components\Grid;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Validation\ValidationException;

class ListDevices extends ListRecords
{
    protected static string $resource = DeviceResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\Action::make('addConnectedDevice')
                // Registration coordinates central and tenant storage; a panel
                // transaction cannot span the tenant connection switches.
                ->databaseTransaction(false)
                ->label('Add device')
                ->icon('heroicon-o-plus')
                ->authorize(fn (): bool => tenant()?->device_integration === DeviceIntegration::GATEWAY && auth('web')->user()?->has_login)
                ->modalHeading('Add attendance device')
                ->modalDescription('Add the device once. The connection and available commands are set up automatically.')
                ->schema([
                    TextInput::make('name')->label('Device name')->required()->maxLength(255),
                    TextInput::make('serial_number')->label('Serial number')->required()->regex('/^[A-Za-z0-9_-]{1,64}$/')->helperText('Find this in the device’s information menu. Registration connects automatically after you add the device.'),
                    TextInput::make('device_ip')->label('Device IP address')->ip()->helperText('Optional: the device’s local network address, kept for reference.'),
                    TextInput::make('location')->label('Location')->maxLength(255),
                    Select::make('direction')->label('Direction')->options(Device::DIRECTION_OPTIONS)->default('DEVICE')->required()->helperText('Classifies new punches without changing raw device records. Terminal punch-state changes depend on its firmware.'),
                    Select::make('timezone')->label('Device time zone')->helperText('Choose the timezone where this device is installed. Time sync uses this zone, even when the company is in another country.')->options(array_combine(timezone_identifiers_list(), timezone_identifiers_list()))->searchable()->default(fn () => TenantTimezone::get())->required(),
                ])
                ->modalSubmitActionLabel('Add device')
                ->action(function (array $data) {
                    $device = app(TenantDeviceRegistration::class)->register($data);
                    Notification::make()->title('Device added')->body('Its registration is being connected automatically. Configure the device to use the company gateway address, then it will appear online.')->success()->send();
                    $this->redirect(DeviceResource::getUrl('index'));
                }),
            Actions\Action::make('syncEbioDevices')
                ->authorize(fn (): bool => DeviceIntegration::usesEbio())
                ->label('Sync devices')
                ->icon('heroicon-o-arrow-path')
                ->color('warning')
                ->requiresConfirmation()
                ->modalHeading('Sync devices')
                ->action(function () {
                    try {
                        $service = new EbioSoapService;
                        $result = $service->syncDevices(tenant());

                        Notification::make()
                            ->title('Sync Complete')
                            ->body("Successfully synced {$result['synced']} devices.")
                            ->success()
                            ->send();
                    } catch (\Exception $e) {
                        Notification::make()
                            ->title('Sync Failed')
                            ->body('The device action could not be completed. Contact your administrator.')
                            ->danger()
                            ->send();
                    }
                }),
            Actions\Action::make('runCommand')
                ->authorize(fn (): bool => in_array(tenant()?->device_integration, [DeviceIntegration::EBIO, DeviceIntegration::GATEWAY], true))
                ->label('Run Command')
                ->icon('heroicon-o-command-line')
                ->color('primary')
                ->form([
                    Select::make('device_id')
                        ->label('Select Device')
                        ->options(function () {
                            return Device::all()->mapWithKeys(function ($d) {
                                return [$d->id => $d->name ?: $d->serial_number];
                            });
                        })
                        ->required()
                        ->searchable()
                        ->live()
                        ->afterStateUpdated(fn (callable $set) => $set('command', null))
                        ->default(fn () => Device::count() === 1 ? Device::first()->id : null),
                    Select::make('command')
                        ->label('Select Command')
                        ->live()
                        ->options(function (callable $get) {
                            $deviceId = $get('device_id');
                            $device = $deviceId ? Device::find($deviceId) : null;
                            if ($device && tenant()?->device_integration === DeviceIntegration::GATEWAY) {
                                return collect(GatewayCommands::RUNNABLE_OPERATIONS)
                                    ->filter(fn ($label, $operation) => app(DeviceCommandService::class)->available($device, $operation))
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
                    TextInput::make('confirm')
                        ->label("Type 'CONFIRM' to execute this command")

                        ->required()
                        ->rule(function () {
                            return function (string $attribute, $value, \Closure $fail) {
                                if ($value !== 'CONFIRM') {
                                    $fail("You must type 'CONFIRM' exactly (all caps) to execute this command.");
                                }
                            };
                        })
                        ->hidden(fn ($get) => ! in_array($get('command'), ['clear_logs', 'reboot', 'unlock_door', 'reset_transaction_stamp', 'reset_op_stamp'])),
                ])
                ->action(function (array $data) {
                    $device = Device::find($data['device_id']);
                    if (! $device) {
                        return;
                    }

                    app(DeviceCommandService::class)->request($device, $data['command']);

                    Notification::make()
                        ->title('Command Queued')
                        ->body("The '{$data['command']}' command has been queued for sync.")
                        ->success()
                        ->send();
                }),
            Actions\CreateAction::make()
                ->authorize(fn (): bool => DeviceIntegration::usesEbio())
                ->label('Add Device')
                ->icon('heroicon-o-plus')
                ->modalHeading('Add device')
                ->form([
                    Grid::make(2)->schema([
                        TextInput::make('serial_number')
                            ->required()
                            ->label('Serial Number')
                            ->columnSpan('full'),
                        TextInput::make('name')
                            ->required()
                            ->label('Device Name'),
                        TextInput::make('location')
                            ->required()
                            ->label('Location'),
                        Select::make('direction')
                            ->options(['IN' => 'IN', 'OUT' => 'OUT', 'OTHER' => 'OTHER'])
                            ->required()
                            ->label('Direction'),
                        TextInput::make('device_type')
                            ->default('Attendance')
                            ->required()
                            ->label('Device Type'),
                        TextInput::make('time_zone')
                            ->default('Asia/Kolkata')
                            ->required()
                            ->label('Time Zone'),
                        TextInput::make('activation_code')
                            ->default('0')
                            ->label('Activation Code'),
                        Select::make('is_attendance_device')
                            ->options(['true' => 'Yes', 'false' => 'No'])
                            ->default('true')
                            ->required()
                            ->label('Is Attendance Device'),
                    ]),
                ])
                ->using(function (array $data, string $model): Model {
                    $service = new EbioSoapService;

                    try {
                        $success = $service->addDevice(tenant(), $data);
                        if (! $success) {
                            throw new \Exception('eBioServer API rejected the device addition.');
                        }
                    } catch (\Exception $e) {
                        Notification::make()
                            ->title('Add Device Failed')
                            ->body('The device action could not be completed. Contact your administrator.')
                            ->danger()
                            ->send();

                        throw ValidationException::withMessages([
                            'serial_number' => 'The device could not be added. Contact your administrator.',
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
                        ],
                    ]);
                }),
        ];
    }
}
