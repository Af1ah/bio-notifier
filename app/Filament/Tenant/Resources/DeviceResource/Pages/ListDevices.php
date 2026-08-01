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
            Actions\Action::make('syncEbioDevices')
                ->label('Sync from eBioServer')
                ->icon('heroicon-o-arrow-path')
                ->color('warning')
                ->requiresConfirmation()
                ->modalHeading('Sync Devices from eBioServer')
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
                            ->body($e->getMessage())
                            ->danger()
                            ->send();
                    }
                }),
            Actions\Action::make('runCommand')
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
                            $options = [
                                'reboot' => 'Reboot Device',
                                'clear_logs' => 'CRITICAL: Clear Attendance Logs',
                                'reset_transaction_stamp' => 'Reset Transaction Stamp',
                                'reset_op_stamp' => 'Reset OP Stamp',
                                'unlock_door' => 'Unlock Door',
                            ];

                            
                            return $options;
                        }),
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

                    $command = \App\Models\DeviceCommand::create([
                        'device_id' => $device->id,
                        'command_type' => $data['command'],
                        'command_content' => "eBioServer SOAP Command: {$data['command']}",
                        'status' => 'pending',
                    ]);

                    \App\Jobs\EbioDeviceCommandJob::dispatch(tenant(), $device->serial_number, $data['command'], $command->id);
                    
                    \Filament\Notifications\Notification::make()
                        ->title('Command Queued')
                        ->body("The '{$data['command']}' command has been queued for sync.")
                        ->success()
                        ->send();
                }),
            Actions\CreateAction::make()
                ->label('Add Device')
                ->icon('heroicon-o-plus')
                ->modalHeading('Add New Device to eBioServer')
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
                            ->body($e->getMessage())
                            ->danger()
                            ->send();
                        
                        throw \Illuminate\Validation\ValidationException::withMessages([
                            'serial_number' => $e->getMessage()
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
