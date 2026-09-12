<?php

namespace App\Filament\Tenant\Resources\UserResource\Pages;

use Filament\Actions;
use Filament\Resources\Pages\ViewRecord;
use App\Filament\Tenant\Resources\UserResource;

class ViewUser extends ViewRecord
{
    protected static string $resource = UserResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\Action::make('enrollOnDevice')
                ->label('Enroll Biometric on Device')
                ->icon('heroicon-o-finger-print')
                ->color('info')
                ->modalHeading(fn () => "Biometric Enrollment: {$this->record->name} (PIN: {$this->record->pin})")
                ->modalDescription('Command a Matrix device to initiate on-device biometric or card capture.')
                ->form([
                    \Filament\Forms\Components\Select::make('device_id')
                        ->label('Select Matrix Device')
                        ->options(function () {
                            return \App\Models\Device::where('vendor', 'matrix')
                                ->get()
                                ->mapWithKeys(fn ($d) => [$d->id => "{$d->name} ({$d->ip_address})"]);
                        })
                        ->default(fn () => \App\Models\Device::where('vendor', 'matrix')->first()?->id)
                        ->required()
                        ->live(),
                    \Filament\Forms\Components\Select::make('method')
                        ->label('Enrollment Method')
                        ->options(function ($get) {
                            $deviceId = $get('device_id');
                            if (! $deviceId) {
                                return [];
                            }
                            $dev = \App\Models\Device::find($deviceId);
                            $methods = $dev?->getEnrollmentMethods() ?? ['face', 'card', 'special_card'];

                            $labels = [
                                'face' => 'Face Recognition',
                                'finger' => 'Fingerprint',
                                'card' => 'RFID Card',
                                'special_card' => 'Special Function Card',
                            ];

                            $available = [];
                            foreach ($methods as $m) {
                                if (isset($labels[$m])) {
                                    $available[$m] = $labels[$m];
                                }
                            }

                            return $available;
                        })
                        ->required()
                        ->live(),
                    \Filament\Forms\Components\Select::make('sp_fn_id')
                        ->label('Special Function')
                        ->visible(fn ($get) => $get('method') === 'special_card')
                        ->options([
                            18 => 'Door Lock',
                            19 => 'Door Unlock',
                            20 => 'Door Normal',
                            21 => 'Clear Alarm',
                            1 => 'Official Work - IN',
                            2 => 'Official Work - OUT',
                            3 => 'Short Leave - IN',
                            4 => 'Short Leave - OUT',
                            5 => 'Regular - IN',
                            6 => 'Regular - OUT',
                        ])
                        ->default(19)
                        ->required(fn ($get) => $get('method') === 'special_card'),
                ])
                ->action(function (array $data) {
                    $record = $this->record;
                    $device = \App\Models\Device::find($data['device_id']);
                    if (! $device) {
                        \Filament\Notifications\Notification::make()->title('Device not found')->danger()->send();
                        return;
                    }

                    if (! $record->pin) {
                        \Filament\Notifications\Notification::make()->title('User has no PIN')->body('A User ID (PIN) is required for device enrollment.')->danger()->send();
                        return;
                    }

                    $extra = [];
                    if ($data['method'] === 'special_card' && isset($data['sp_fn_id'])) {
                        $extra['sp_fn_id'] = $data['sp_fn_id'];
                    }

                    $cmd = app(\App\Services\Attendance\DeviceCommandBuilder::class)->enrollBiometric($device, (string) $record->pin, $data['method'], $extra);

                    if ($cmd->status === 'failed') {
                        \Filament\Notifications\Notification::make()
                            ->title('Enrollment Request Failed')
                            ->body($cmd->response ?: 'Could not initiate enrollment on device.')
                            ->danger()
                            ->send();
                    } else {
                        $methodLabel = match ($data['method']) {
                            'face' => 'Face Recognition',
                            'finger' => 'Fingerprint',
                            'card' => 'RFID Card',
                            'special_card' => 'Special Function Card',
                            default => ucfirst($data['method']),
                        };

                        \Filament\Notifications\Notification::make()
                            ->title("{$methodLabel} Enrollment Initiated")
                            ->body("Please proceed on the {$device->name} screen to complete capture.")
                            ->success()
                            ->persistent()
                            ->send();
                    }
                }),
            Actions\Action::make('pushToDevice')
                ->label('Push to Device')
                ->icon('heroicon-o-arrow-up-on-square')
                ->color('success')
                ->form([
                    \Filament\Forms\Components\Select::make('device_id')
                        ->label('Select Device')
                        ->options(\App\Models\Device::all()->mapWithKeys(fn ($d) => [$d->id => ($d->name ?: $d->serial_number) . " (" . ucfirst($d->vendor) . ")"])->toArray())
                        ->default(fn () => \App\Models\Device::first()?->id)
                        ->required(),
                    \Filament\Forms\Components\CheckboxList::make('sync_properties')
                        ->label('What to sync?')
                        ->options([
                            'profile' => 'Basic Profile (Name, Card, Password, Access)',
                            'biometrics' => 'Biometrics (Fingerprints)',
                        ])
                        ->default(['profile', 'biometrics'])
                        ->required(),
                ])
                ->action(function (array $data) {
                    $record = $this->record;
                    $device = \App\Models\Device::find($data['device_id']);
                    if (! $device) {
                        \Filament\Notifications\Notification::make()->title('Device not found')->danger()->send();
                        return;
                    }

                    if (! $record->pin) {
                        \Filament\Notifications\Notification::make()->title('User has no PIN')->body('A User ID (PIN) is required for device sync.')->danger()->send();
                        return;
                    }

                    $builder = app(\App\Services\Attendance\DeviceCommandBuilder::class);
                    $syncProfile = in_array('profile', $data['sync_properties']);
                    $syncBio = in_array('biometrics', $data['sync_properties']);

                    $cmd = null;
                    if ($syncProfile) {
                        $cmd = $builder->addUser($device, [
                            'pin' => $record->pin,
                            'name' => $record->name,
                            'card' => $record->card_number,
                            'privilege' => $record->privilege,
                            'password' => $record->device_password,
                            'group' => $record->group ?? 1,
                            'user_id' => $record->id,
                            'is_enabled' => $record->is_enabled,
                        ]);
                    }

                    if ($syncBio && !empty($record->fingerprints) && is_array($record->fingerprints)) {
                        foreach ($record->fingerprints as $key => $fp) {
                            $id = is_numeric($key) ? (int) $key : ($fp['finger_id'] ?? $fp['fid'] ?? null);
                            if ($id !== null && isset($fp['tmp'])) {
                                $builder->addFingerprint($device, $record->pin, $id, $fp['tmp']);
                            }
                        }
                    }

                    if (in_array($device->vendor, ['matrix', 'hikvision'])) {
                        if ($cmd && $cmd->status === 'failed') {
                            \Filament\Notifications\Notification::make()
                                ->title('Device Push Failed')
                                ->body($cmd->response ?: 'Could not push user to device.')
                                ->danger()
                                ->persistent()
                                ->send();
                        } else {
                            \Filament\Notifications\Notification::make()
                                ->title('User Synced Successfully')
                                ->body("User '{$record->name}' was synced to {$device->name}.")
                                ->success()
                                ->send();
                        }
                    } else {
                        \Filament\Notifications\Notification::make()
                            ->title('Command Queued')
                            ->body("User '{$record->name}' queued for next device poll.")
                            ->success()
                            ->send();
                    }
                }),
            Actions\EditAction::make(),
        ];
    }
}
