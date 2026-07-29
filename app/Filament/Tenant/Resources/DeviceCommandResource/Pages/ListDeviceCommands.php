<?php

namespace App\Filament\Tenant\Resources\DeviceCommandResource\Pages;

use Filament\Actions;
use Filament\Resources\Pages\ListRecords;
use App\Filament\Tenant\Resources\DeviceCommandResource;

class ListDeviceCommands extends ListRecords
{
    protected static string $resource = DeviceCommandResource::class;

    protected function getHeaderActions(): array
    {
        return [
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
                        ->live()
                        ->afterStateUpdated(fn (callable $set) => $set('command', null))
                        ->default(fn () => \App\Models\Device::count() === 1 ? \App\Models\Device::first()->id : null),
                    \Filament\Forms\Components\Select::make('command')
                        ->label('Select Command')
                        ->live()
                        ->options(function (callable $get) {
                            $deviceId = $get('device_id');
                            $options = [
                                'info' => 'Get Device Info',
                                'reboot' => 'Reboot Device',
                                'checkConnection' => 'Check Connection',
                                'syncTime' => 'Sync Time',
                                'queryAllUsers' => 'Pull All Users',
                                'queryAllFingerprints' => 'Pull All Fingerprints',
                                'queryAttendanceLogs' => 'Pull All Attendance Logs (Recovery)',
                                'clearAttendanceLogs' => 'CRITICAL: Clear Attendance Logs',
                                'clearUsers' => 'CRITICAL: Clear All Users',
                                'clearAllData' => 'CRITICAL: Clear All Data (Hard Reset)',
                            ];

                            
                            return $options;
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
                        ->hidden(fn ($get) => !in_array($get('command'), ['clearAttendanceLogs', 'clearUsers', 'clearAllData', 'reboot'])),
                ])
                ->action(function (array $data) {
                    $device = \App\Models\Device::find($data['device_id']);
                    if (!$device) return;

                    $builder = app(\App\Services\Attendance\DeviceCommandBuilder::class);
                    $commandMethod = $data['command'];
                    
                    if (method_exists($builder, $commandMethod)) {
                        $builder->$commandMethod($device);
                        
                        \Filament\Notifications\Notification::make()
                            ->title('Command Queued')
                            ->body("The '{$data['command']}' command will be executed on the next device poll.")
                            ->success()
                            ->send();
                    }
                }),
            Actions\CreateAction::make(),
        ];
    }
}
