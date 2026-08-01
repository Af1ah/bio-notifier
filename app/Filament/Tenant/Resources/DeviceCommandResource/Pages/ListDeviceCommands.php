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
                        ->options([
                            'reboot' => 'Reboot Device',
                            'clear_logs' => 'CRITICAL: Clear Attendance Logs',
                            'reset_transaction_stamp' => 'Reset Transaction Stamp',
                            'reset_op_stamp' => 'Reset OP Stamp',
                            'unlock_door' => 'Unlock Door',
                        ])
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
            Actions\CreateAction::make(),
        ];
    }
}
