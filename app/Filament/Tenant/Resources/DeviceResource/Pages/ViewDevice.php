<?php

namespace App\Filament\Tenant\Resources\DeviceResource\Pages;

use Filament\Actions;
use Filament\Resources\Pages\ViewRecord;
use App\Filament\Tenant\Resources\DeviceResource;

class ViewDevice extends ViewRecord
{
    protected static string $resource = DeviceResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\Action::make('unlockDoor')
                ->label('Unlock Door')
                ->icon('heroicon-o-lock-open')
                ->color('success')
                ->requiresConfirmation()
                ->modalHeading('Unlock Door')
                ->modalDescription('Send an unlock command to the door controller.')
                ->action(function () {
                    $cmd = app(\App\Services\Attendance\DeviceCommandBuilder::class)->unlockDoor($this->record);
                    \Filament\Notifications\Notification::make()
                        ->title($cmd->status === 'failed' ? 'Unlock Failed' : 'Door Unlocked')
                        ->body($cmd->response ?: 'Unlock command processed.')
                        ->status($cmd->status === 'failed' ? 'danger' : 'success')
                        ->send();
                }),
            Actions\Action::make('lockDoor')
                ->label('Lock Door')
                ->icon('heroicon-o-lock-closed')
                ->color('danger')
                ->requiresConfirmation()
                ->modalHeading('Lock Door')
                ->modalDescription('Send a lock command to the door controller.')
                ->action(function () {
                    $cmd = app(\App\Services\Attendance\DeviceCommandBuilder::class)->lockDoor($this->record);
                    \Filament\Notifications\Notification::make()
                        ->title($cmd->status === 'failed' ? 'Lock Failed' : 'Door Locked')
                        ->body($cmd->response ?: 'Lock command processed.')
                        ->status($cmd->status === 'failed' ? 'danger' : 'success')
                        ->send();
                }),
            Actions\Action::make('normalizeDoor')
                ->label('Set to Normal')
                ->icon('heroicon-o-shield-check')
                ->color('info')
                ->requiresConfirmation()
                ->modalHeading('Set Door to Normal State')
                ->modalDescription('Return the door to standard access evaluation mode.')
                ->action(function () {
                    $cmd = app(\App\Services\Attendance\DeviceCommandBuilder::class)->normalizeDoor($this->record);
                    \Filament\Notifications\Notification::make()
                        ->title($cmd->status === 'failed' ? 'Command Failed' : 'Door Normalized')
                        ->body($cmd->response ?: 'Door returned to normal mode.')
                        ->status($cmd->status === 'failed' ? 'danger' : 'success')
                        ->send();
                }),
            Actions\Action::make('enableEnrollment')
                ->label('Enable Device Enrollment')
                ->icon('heroicon-o-finger-print')
                ->color('warning')
                ->requiresConfirmation()
                ->modalHeading('Enable Enrollment Mode on Device')
                ->modalDescription('Send API command to activate on-device biometric enrollment mode (enroll-on-device=1).')
                ->visible(fn () => $this->record->vendor === 'matrix')
                ->action(function () {
                    $cmd = app(\App\Services\Attendance\DeviceCommandBuilder::class)->enableEnrollment($this->record);
                    \Filament\Notifications\Notification::make()
                        ->title($cmd->status === 'failed' ? 'Failed to Enable Enrollment' : 'Enrollment Enabled')
                        ->body($cmd->response ?: 'Device enrollment mode activated via API.')
                        ->status($cmd->status === 'failed' ? 'danger' : 'success')
                        ->send();
                }),
            Actions\Action::make('manageMatrixUser')
                ->label('Manage Matrix User')
                ->icon('heroicon-o-users')
                ->color('primary')
                ->visible(fn () => $this->record->vendor === 'matrix')
                ->modalHeading('Matrix Device User Management')
                ->modalDescription('Enter an existing User ID and move out of the field to load it. Edit the user or card fields, then save directly to the Matrix device.')
                ->modalSubmitActionLabel('Save to Matrix Device')
                ->modalWidth('7xl')
                ->form([
                    \Filament\Schemas\Components\Section::make('User')
                        ->schema([
                            \Filament\Forms\Components\TextInput::make('user_id')
                                ->label('User ID')
                                ->required()
                                ->maxLength(10)
                                ->regex('/^[A-Za-z0-9]+$/')
                                ->validationMessages([
                                    'max' => 'User ID can be up to 10 letters or numbers.',
                                    'regex' => 'User ID can contain only letters and numbers.',
                                ])
                                ->live(onBlur: true)
                                ->helperText('Enter an existing ID to load its settings, or a new unique ID to create a user.')
                                ->afterStateUpdated(function (?string $state, callable $set): void {
                                    if (blank($state)) {
                                        return;
                                    }

                                    if (! preg_match('/^[A-Za-z0-9]{1,10}$/', $state)) {
                                        return;
                                    }

                                    $result = app(\App\Services\Attendance\MatrixDeviceService::class)->getUser($this->record, $state);
                                    if (! $result['success']) {
                                        \Filament\Notifications\Notification::make()
                                            ->title('Unable to Load Matrix User')
                                            ->body($result['message'])
                                            ->danger()
                                            ->persistent()
                                            ->send();

                                        return;
                                    }

                                    foreach ($result['user'] as $field => $value) {
                                        $set($field, $value);
                                    }
                                }),
                            \Filament\Forms\Components\TextInput::make('reference_id')
                                ->label('Reference ID')
                                ->required()
                                ->maxLength(8)
                                ->regex('/^\d{1,8}$/')
                                ->validationMessages([
                                    'regex' => 'Reference ID must contain 1 to 8 digits.',
                                ])
                                ->helperText('Numeric ID recorded by Matrix attendance events.'),
                            \Filament\Forms\Components\TextInput::make('name')
                                ->label('Name')
                                ->maxLength(15)
                                ->helperText('Matrix accepts letters, numbers, and spaces only.'),
                            \Filament\Forms\Components\Toggle::make('user_active')
                                ->label('Enabled')
                                ->default(true),
                            \Filament\Forms\Components\Toggle::make('vip')
                                ->label('VIP')
                                ->default(false),
                        ])
                        ->columns(3),
                    \Filament\Schemas\Components\Section::make('Card Details')
                        ->description('Card 1 and Card 2 are stored separately by the Matrix device. Clear a value and save to remove that card.')
                        ->schema([
                            \Filament\Forms\Components\TextInput::make('card1')
                                ->label('Card 1')
                                ->maxLength(20)
                                ->nullable()
                                ->regex('/^\d{1,20}$/')
                                ->validationMessages([
                                    'regex' => 'Card 1 must contain up to 20 digits.',
                                ])
                                ->autocomplete('off'),
                            \Filament\Forms\Components\Toggle::make('card1_is_raw_rfid')
                                ->label('Card 1 is raw RFID')
                                ->helperText('Converts the 24-bit reader value to the Matrix card value when saved.')
                                ->default(false),
                            \Filament\Forms\Components\TextInput::make('card2')
                                ->label('Card 2')
                                ->maxLength(20)
                                ->nullable()
                                ->regex('/^\d{1,20}$/')
                                ->validationMessages([
                                    'regex' => 'Card 2 must contain up to 20 digits.',
                                ])
                                ->autocomplete('off'),
                            \Filament\Forms\Components\Toggle::make('card2_is_raw_rfid')
                                ->label('Card 2 is raw RFID')
                                ->helperText('Converts the 24-bit reader value to the Matrix card value when saved.')
                                ->default(false),
                        ])
                        ->columns(2),
                ])
                ->action(function (array $data): void {
                    foreach (['card1', 'card2'] as $field) {
                        if (empty($data["{$field}_is_raw_rfid"]) || blank($data[$field] ?? null)) {
                            continue;
                        }

                        try {
                            $data[$field] = \App\Services\Attendance\MatrixDeviceService::convert24BitRawRfid($data[$field]);
                        } catch (\InvalidArgumentException $exception) {
                            \Filament\Notifications\Notification::make()
                                ->title('Invalid Raw RFID')
                                ->body("{$field}: {$exception->getMessage()}")
                                ->danger()
                                ->persistent()
                                ->send();

                            return;
                        }
                    }

                    $result = app(\App\Services\Attendance\MatrixDeviceService::class)->setUser($this->record, $data);

                    \Filament\Notifications\Notification::make()
                        ->title($result['success'] ? 'Matrix User Saved' : 'Unable to Save Matrix User')
                        ->body($result['message'])
                        ->status($result['success'] ? 'success' : 'danger')
                        ->persistent(! $result['success'])
                        ->send();
                }),
            Actions\Action::make('configureReader')
                ->label('Configure External / Exit Reader')
                ->icon('heroicon-o-arrow-right-on-rectangle')
                ->color('warning')
                ->visible(fn () => $this->record->vendor === 'matrix')
                ->mountUsing(function ($form) {
                    $matrixSvc = app(\App\Services\Attendance\MatrixDeviceService::class);
                    $cfg = $matrixSvc->getReaderConfig($this->record);
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
                    \Filament\Forms\Components\Select::make('reader3')
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
                    \Filament\Forms\Components\Select::make('reader_entry_exit_mode')
                        ->label('Reader Function / Mode')
                        ->options([
                            '1' => 'Exit Reader',
                            '0' => 'Entry Reader',
                        ])
                        ->default('1')
                        ->required(),
                    \Filament\Forms\Components\Select::make('reader_access_mode')
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
                ->action(function (array $data) {
                    $matrixSvc = app(\App\Services\Attendance\MatrixDeviceService::class);
                    $res = $matrixSvc->setReaderConfig($this->record, [
                        'reader3' => (int) $data['reader3'],
                        'reader-entry-exit-mode' => (int) $data['reader_entry_exit_mode'],
                        'reader-access-mode' => (int) $data['reader_access_mode'],
                    ]);

                    if ($res['success']) {
                        \Filament\Notifications\Notification::make()
                            ->title('External Reader Configured')
                            ->body("External reader successfully set to type {$data['reader3']} (" . ($data['reader_entry_exit_mode'] === '1' ? 'Exit' : 'Entry') . ").")
                            ->success()
                            ->send();
                    } else {
                        \Filament\Notifications\Notification::make()
                            ->title('Failed to Configure Reader')
                            ->body($res['message'])
                            ->danger()
                            ->persistent()
                            ->send();
                    }
                }),
            Actions\Action::make('viewDeviceLogs')
                ->label('View Device Logs')
                ->icon('heroicon-o-clipboard-document-list')
                ->color('gray')
                ->visible(fn () => $this->record->vendor === 'matrix')
                ->modalHeading('Recent Device Event Logs (Hardware Logs)')
                ->modalSubmitAction(false)
                ->modalCancelActionLabel('Close')
                ->action(function () {})
                ->form(function () {
                    $matrixSvc = app(\App\Services\Attendance\MatrixDeviceService::class);
                    $countRes = $matrixSvc->getEventCount($this->record);
                    $lines = [];

                    if (! $countRes['success']) {
                        $lines[] = 'Unable to retrieve device event count: ' . $countRes['message'];

                        return [
                            \Filament\Forms\Components\Textarea::make('logs')
                                ->label('Recent Device Events (Directly from Device Hardware)')
                                ->rows(14)
                                ->default(implode("\n", $lines))
                                ->disabled(),
                        ];
                    }

                    $seq = max(1, $countRes['sequence'] - 15);
                    $logRes = $matrixSvc->getEventLogs($this->record, $seq, 20, $countRes['rollover']);
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
                        \Filament\Forms\Components\Textarea::make('logs')
                            ->label('Recent Device Events (Directly from Device Hardware)')
                            ->rows(14)
                            ->default(implode("\n", $lines))
                            ->disabled(),
                    ];
                }),
            Actions\Action::make('checkStatus')
                ->label('Check Status')
                ->icon('heroicon-o-signal')
                ->color('success')
                ->action(function () {
                    if ($this->record->vendor === 'matrix') {
                        $result = app(\App\Services\Attendance\MatrixDeviceService::class)->checkConnection($this->record);
                        if ($result['success']) {
                            $this->record->update([
                                'status' => 'online',
                                'model' => $result['model'] ?? ($this->record->model ?: 'Matrix COSEC'),
                                'last_activity_at' => now(),
                            ]);
                            \Filament\Notifications\Notification::make()
                                ->title('Device Online')
                                ->body($result['message'])
                                ->success()
                                ->send();
                        } else {
                            $this->record->update(['status' => 'offline']);
                            \Filament\Notifications\Notification::make()
                                ->title('Device Connection Failed')
                                ->body($result['message'])
                                ->danger()
                                ->persistent()
                                ->send();
                        }
                    } else {
                        app(\App\Services\Attendance\DeviceCommandBuilder::class)->checkConnection($this->record);
                        \Filament\Notifications\Notification::make()
                            ->title('Check Command Queued')
                            ->body('Connection check command queued.')
                            ->success()
                            ->send();
                    }
                }),
            Actions\EditAction::make(),
        ];
    }
}
