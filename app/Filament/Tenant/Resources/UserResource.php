<?php

namespace App\Filament\Tenant\Resources;

use App\Filament\Tenant\Resources\UserResource\Pages;
use App\Models\Branch;
use App\Models\Department;
use App\Models\Device;
use App\Models\TaskGroup;
use App\Models\User;
use App\Services\Attendance\DeviceCommandBuilder;
use Filament\Actions\Action;
use Filament\Actions\ActionGroup;
use Filament\Actions\BulkAction;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Actions\ViewAction;
use Filament\Forms\Components\CheckboxList;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Infolists\Components\TextEntry;
use Filament\Infolists\Components\ViewEntry;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Group;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;

class UserResource extends Resource
{
    protected static ?string $model = User::class;

    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-users';

    protected static ?int $navigationSort = 3;

    protected static ?string $modelLabel = 'User';

    protected static ?string $recordTitleAttribute = 'name';

    public static function getGloballySearchableAttributes(): array
    {
        return ['name', 'pin', 'email'];
    }

    public static function getGlobalSearchResultIcon(Model $record): string
    {
        return 'heroicon-o-user';
    }

    protected static ?string $pluralModelLabel = 'Users';

    public static function getNavigationGroup(): ?string
    {
        return 'Attendance';
    }

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            Section::make('User Information')
                ->schema([
                    TextInput::make('pin')
                        ->label('User ID (PIN)')
                        ->required()
                        ->unique(ignoreRecord: true)
                        ->autocomplete('off'),
                    TextInput::make('name')
                        ->autocomplete('off'),
                    TextInput::make('email')
                        ->email()
                        ->unique(ignoreRecord: true)
                        ->nullable()
                        ->autocomplete('off'),
                    TextInput::make('whatsapp_number')
                        ->label('WhatsApp Number')
                        ->hint('Include country code without +, e.g., 919876543210')
                        ->tel()
                        ->nullable(),
                    TextInput::make('card_number')
                        ->label('Card Number')
                        ->autocomplete('off'),
                    Select::make('privilege')
                        ->options([
                            0 => 'User',
                            14 => 'Admin',
                        ])
                        ->default(0),
                    TextInput::make('device_password')
                        ->label('Device Password (Numeric)')
                        ->numeric()
                        ->maxLength(8)
                        ->password()
                        ->revealable()
                        ->autocomplete('new-password'),
                    Toggle::make('is_enabled')
                        ->label('Enabled')
                        ->default(true),
                ])
                ->columns(2)
                ->columnSpanFull(),
            Section::make('Enrollment Details')
                ->schema([
                    Select::make('branch_id')
                        ->relationship('branch', 'name')
                        ->getOptionLabelFromRecordUsing(fn ($record) => $record->display_name)
                        ->label('Branch')
                        ->default(fn () => Branch::count() === 1 ? Branch::first()->id : null)
                        ->live()
                        ->nullable(),
                    Select::make('department_id')
                        ->relationship('department', 'name', fn ($query, $get) => $get('branch_id')
                                ? $query->whereHas('branches', fn ($q) => $q->where('branches.id', $get('branch_id')))
                                : $query
                        )
                        ->label('Department')
                        ->default(fn () => Department::count() === 1 ? Department::first()->id : null)
                        ->nullable(),
                    Select::make('group')
                        ->label('Designation / Group')
                        ->options(User::whereNotNull('group')->where('group', '!=', '')->distinct()->pluck('group', 'group'))
                        ->searchable()
                        ->createOptionForm([
                            TextInput::make('name')->required()->label('Name'),
                        ])
                        ->createOptionUsing(fn (array $data) => $data['name'])
                        ->nullable(),
                    Select::make('taskGroups')
                        ->relationship('taskGroups', 'name')
                        ->label('Task Groups')
                        ->multiple()
                        ->searchable()
                        ->default(fn () => TaskGroup::count() === 1 ? [TaskGroup::first()->id] : [])
                        ->createOptionForm([
                            TextInput::make('name')->required(),
                            Textarea::make('description'),
                        ])
                        ->nullable(),
                ])
                ->columns(3)
                ->columnSpanFull()
                ->collapsed(),
        ]);
    }

    public static function infolist(Schema $schema): Schema
    {
        return $schema
            ->components([
                Group::make([
                    Section::make('User Details')
                        ->schema([
                            TextEntry::make('name')
                                ->label('Name')
                                ->weight('bold')
                                ->size('lg'),
                            TextEntry::make('pin')
                                ->label('PIN'),
                            TextEntry::make('whatsapp_number')
                                ->label('WhatsApp Number')
                                ->default('Not Provided'),
                            TextEntry::make('branch.name')
                                ->label('Branch')
                                ->default('Not Assigned'),
                            TextEntry::make('department.name')
                                ->label('Department')
                                ->default('Not Assigned'),
                            TextEntry::make('group')
                                ->label('Designation / Group')
                                ->default('Not Assigned'),
                            TextEntry::make('fingerprints')
                                ->label('Added Fingerprints')
                                ->badge()
                                ->formatStateUsing(function ($state, $record) {
                                    $rawState = $record->fingerprints;
                                    if (empty($rawState) || ! is_array($rawState)) {
                                        return 'None';
                                    }
                                    $fingers = [
                                        0 => 'Left Pinky', 1 => 'Left Ring', 2 => 'Left Middle', 3 => 'Left Index', 4 => 'Left Thumb',
                                        5 => 'Right Thumb', 6 => 'Right Index', 7 => 'Right Middle', 8 => 'Right Ring', 9 => 'Right Pinky',
                                    ];
                                    $added = [];
                                    foreach ($rawState as $key => $fp) {
                                        $id = is_numeric($key) ? (int) $key : ($fp['finger_id'] ?? $fp['fid'] ?? null);
                                        if ($id !== null && isset($fingers[$id])) {
                                            $added[] = $fingers[$id];
                                        } elseif ($id !== null) {
                                            $added[] = 'Finger '.$id;
                                        }
                                    }

                                    return count($added) > 0 ? implode(', ', $added) : count($rawState).' Template(s)';
                                })
                                ->color('success'),
                        ])->columns(3),

                    Section::make('Shift Details')
                        ->schema([
                            TextEntry::make('shift')
                                ->label('Active Shift')
                                ->formatStateUsing(function ($record) {
                                    $schedule = $record->getActiveSchedule();
                                    if (! $schedule) {
                                        return 'No Active Schedule';
                                    }
                                    $rules = $schedule->rules;
                                    $time = ($rules['start_time'] ?? '--:--').' to '.($rules['end_time'] ?? '--:--');

                                    return $schedule->name.' ('.$time.')';
                                }),
                        ]),
                ])->columnSpanFull(),

                Section::make('Attendance Calendar')
                    ->schema([
                        ViewEntry::make('calendar')
                            ->hiddenLabel()
                            ->view('filament.tenant.components.attendance-calendar')
                            ->columnSpanFull(),
                    ])->columnSpanFull(),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('pin')
                    ->searchable()
                    ->sortable(),
                Tables\Columns\TextColumn::make('name')
                    ->searchable(),
                Tables\Columns\TextColumn::make('card_number')
                    ->label('Card')
                    ->toggleable(isToggledHiddenByDefault: true),
                Tables\Columns\TextColumn::make('whatsapp_number')
                    ->label('WhatsApp Number')
                    ->searchable()
                    ->toggleable(isToggledHiddenByDefault: true),
                Tables\Columns\TextColumn::make('branch.name')
                    ->label('Branch')
                    ->sortable()
                    ->searchable()
                    ->toggleable(),
                Tables\Columns\TextColumn::make('department.name')
                    ->label('Department')
                    ->sortable()
                    ->searchable()
                    ->toggleable(),
                Tables\Columns\TextColumn::make('group')
                    ->label('Designation/Group')
                    ->sortable()
                    ->searchable()
                    ->toggleable(isToggledHiddenByDefault: true),
                Tables\Columns\TextColumn::make('privilege')
                    ->badge()
                    ->formatStateUsing(fn ($state) => $state === 14 ? 'Admin' : 'User')
                    ->color(fn ($state): string => $state === 14 ? 'primary' : 'gray'),
                Tables\Columns\IconColumn::make('is_enabled')
                    ->label('Enabled')
                    ->boolean(),
            ])
            ->filters([
                Tables\Filters\SelectFilter::make('privilege')
                    ->options([
                        0 => 'User',
                        14 => 'Admin',
                    ])
                    ->default(0),
                Tables\Filters\SelectFilter::make('branch_id')
                    ->relationship('branch', 'name')
                    ->getOptionLabelFromRecordUsing(fn ($record) => $record->display_name)
                    ->label('Branch'),
                Tables\Filters\SelectFilter::make('department_id')
                    ->relationship('department', 'name')
                    ->label('Department'),
                Tables\Filters\SelectFilter::make('group')
                    ->label('Designation / Group')
                    ->options(fn () => User::whereNotNull('group')->where('group', '!=', '')->distinct()->pluck('group', 'group')->toArray()),
                Tables\Filters\TernaryFilter::make('is_enabled')
                    ->default(true),
            ])
            ->recordActions([
                ActionGroup::make([
                    ViewAction::make(),
                    EditAction::make(),
                    Action::make('enrollOnDevice')
                        ->label('Enroll Biometric on Device')
                        ->icon('heroicon-o-finger-print')
                        ->color('info')
                        ->modalHeading(fn (User $record) => "Biometric Enrollment: {$record->name} (PIN: {$record->pin})")
                        ->modalDescription('Command a Matrix device to initiate on-device biometric or card capture.')
                        ->form([
                            Select::make('device_id')
                                ->label('Select Matrix Device')
                                ->options(function () {
                                    return Device::where('vendor', 'matrix')
                                        ->get()
                                        ->mapWithKeys(fn (Device $d) => [$d->id => "{$d->name} ({$d->ip_address})"]);
                                })
                                ->default(fn () => Device::where('vendor', 'matrix')->first()?->id)
                                ->required()
                                ->live(),
                            Select::make('method')
                                ->label('Enrollment Method')
                                ->options(function ($get) {
                                    $deviceId = $get('device_id');
                                    if (! $deviceId) {
                                        return [];
                                    }
                                    $dev = Device::find($deviceId);
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
                            Select::make('sp_fn_id')
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
                        ->action(function (User $record, array $data) {
                            $device = Device::find($data['device_id']);
                            if (! $device) {
                                Notification::make()->title('Device not found')->danger()->send();
                                return;
                            }

                            if (! $record->pin) {
                                Notification::make()->title('User has no PIN')->body('A User ID (PIN) is required for device enrollment.')->danger()->send();
                                return;
                            }

                            $extra = [];
                            if ($data['method'] === 'special_card' && isset($data['sp_fn_id'])) {
                                $extra['sp_fn_id'] = $data['sp_fn_id'];
                            }

                            $cmd = app(DeviceCommandBuilder::class)->enrollBiometric($device, (string) $record->pin, $data['method'], $extra);

                            if ($cmd->status === 'failed') {
                                Notification::make()
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

                                Notification::make()
                                    ->title("{$methodLabel} Enrollment Initiated")
                                    ->body("Please proceed on the {$device->name} screen to complete capture.")
                                    ->success()
                                    ->persistent()
                                    ->send();
                            }
                        }),
                    Action::make('pushToDevice')
                        ->label('Push to Device')
                        ->icon('heroicon-o-arrow-up-on-square')
                        ->color('success')
                        ->form([
                            Select::make('device_id')
                                ->label('Select Device')
                                ->options(Device::all()->mapWithKeys(fn ($d) => [$d->id => ($d->name ?: $d->serial_number) . " (" . ucfirst($d->vendor) . ")"])->toArray())
                                ->default(fn () => Device::first()?->id)
                                ->required(),
                            CheckboxList::make('sync_properties')
                                ->label('What to sync?')
                                ->options([
                                    'profile' => 'Basic Profile (Name, Card, Password, Access)',
                                    'biometrics' => 'Biometrics (Fingerprints)',
                                ])
                                ->default(['profile', 'biometrics'])
                                ->required(),
                        ])
                        ->action(function (User $record, array $data) {
                            $device = Device::find($data['device_id']);
                            if (! $device) {
                                Notification::make()->title('Device not found')->danger()->send();
                                return;
                            }

                            if (! $record->pin) {
                                Notification::make()->title('User has no PIN')->body('A User ID (PIN) is required for device sync.')->danger()->send();
                                return;
                            }

                            $builder = app(DeviceCommandBuilder::class);
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
                                    Notification::make()
                                        ->title('Device Push Failed')
                                        ->body($cmd->response ?: 'Could not push user to device.')
                                        ->danger()
                                        ->persistent()
                                        ->send();
                                } else {
                                    Notification::make()
                                        ->title('User Synced Successfully')
                                        ->body("User '{$record->name}' was synced to {$device->name}.")
                                        ->success()
                                        ->send();
                                }
                            } else {
                                Notification::make()
                                    ->title('Command Queued')
                                    ->body("User '{$record->name}' queued for next device poll.")
                                    ->success()
                                    ->send();
                            }
                        }),
                ])
                ->icon('heroicon-m-ellipsis-vertical')
                ->tooltip('Actions'),
            ])
            ->bulkActions([
                BulkActionGroup::make([
                    DeleteBulkAction::make(),
                    BulkAction::make('pushToDevice')
                        ->icon('heroicon-o-arrow-up-on-square')
                        ->color('success')
                        ->label('Push to Device')
                        ->form([
                            Select::make('device_ids')
                                ->label('Select Devices to Sync To')
                                ->multiple()
                                ->options(Device::all()->mapWithKeys(fn ($d) => [$d->id => $d->name ?: $d->serial_number])->toArray())
                                ->default(fn () => Device::count() === 1 ? [Device::first()->id] : [])
                                ->required(),
                            CheckboxList::make('sync_properties')
                                ->label('What to sync?')
                                ->options([
                                    'profile' => 'Basic Profile (Name, Card, Privilege, Password, Access)',
                                    'biometrics' => 'Biometrics (Fingerprints)',
                                ])
                                ->default(['profile', 'biometrics'])
                                ->required()
                                ->columns(1),
                        ])
                        ->action(function (Collection $records, array $data) {
                            $deviceIds = $data['device_ids'] ?? [];
                            if (empty($deviceIds) && Device::count() === 1) {
                                $deviceIds = [Device::first()->id];
                            }

                            $syncProfile = in_array('profile', $data['sync_properties']);
                            $syncBio = in_array('biometrics', $data['sync_properties']);

                            $devices = Device::whereIn('id', $deviceIds)->get();
                            $builder = app(DeviceCommandBuilder::class);

                            $errors = [];
                            $directSuccess = 0;
                            $admsQueued = 0;

                            foreach ($devices as $device) {
                                foreach ($records as $user) {
                                    if (! $user->pin) {
                                        $errors[] = "User '{$user->name}' has no PIN / User ID.";
                                        continue;
                                    }

                                    if ($syncProfile) {
                                        $cmd = $builder->addUser($device, [
                                            'pin' => $user->pin,
                                            'name' => $user->name,
                                            'card' => $user->card_number,
                                            'privilege' => $user->privilege,
                                            'password' => $user->device_password,
                                            'group' => $user->group ?? 1,
                                            'user_id' => $user->id,
                                            'is_enabled' => $user->is_enabled,
                                        ]);

                                        if (in_array($device->vendor, ['matrix', 'hikvision'])) {
                                            if ($cmd->status === 'failed') {
                                                $errors[] = "{$device->name} ({$user->name}): {$cmd->response}";
                                            } else {
                                                $directSuccess++;
                                            }
                                        } else {
                                            $admsQueued++;
                                        }
                                    }

                                    if ($syncBio) {
                                        $fingerprints = $user->fingerprints;
                                        if (is_array($fingerprints)) {
                                            foreach ($fingerprints as $key => $fp) {
                                                $id = is_numeric($key) ? (int) $key : ($fp['finger_id'] ?? $fp['fid'] ?? null);
                                                if ($id !== null && isset($fp['tmp'])) {
                                                    $builder->addFingerprint($device, $user->pin, $id, $fp['tmp']);
                                                }
                                            }
                                        }
                                    }
                                }
                            }

                            if (! empty($errors)) {
                                Notification::make()
                                    ->title('Device Sync Errors')
                                    ->body(implode("\n", array_slice($errors, 0, 5)))
                                    ->danger()
                                    ->persistent()
                                    ->send();
                            } elseif ($directSuccess > 0) {
                                Notification::make()
                                    ->title('Users Synced')
                                    ->body("Successfully synced {$records->count()} user(s) to device(s).")
                                    ->success()
                                    ->send();
                            } else {
                                Notification::make()
                                    ->title('Commands Queued')
                                    ->body("{$records->count()} user(s) queued for device poll.")
                                    ->success()
                                    ->send();
                            }
                        })
                        ->deselectRecordsAfterCompletion(),
                    BulkAction::make('deleteFromDevice')
                        ->icon('heroicon-o-trash')
                        ->color('danger')
                        ->label('Delete from Device')
                        ->form([
                            Select::make('device_id')
                                ->label('Select Device')
                                ->options(Device::all()->mapWithKeys(fn ($d) => [$d->id => $d->name ?: $d->serial_number])->toArray())
                                ->required(),
                        ])
                        ->action(function (Collection $records, array $data) {
                            $device = Device::find($data['device_id']);

                            if ($device) {
                                $errors = [];
                                $successCount = 0;

                                foreach ($records as $record) {
                                    if (! $record->pin) {
                                        continue;
                                    }
                                    $cmd = app(DeviceCommandBuilder::class)->deleteUser($device, $record->pin);
                                    if (in_array($device->vendor, ['matrix', 'hikvision'])) {
                                        if ($cmd->status === 'failed') {
                                            $errors[] = "{$record->name}: {$cmd->response}";
                                        } else {
                                            $successCount++;
                                        }
                                    } else {
                                        $successCount++;
                                    }
                                }

                                if (! empty($errors)) {
                                    Notification::make()
                                        ->title('Delete Errors')
                                        ->body(implode("\n", array_slice($errors, 0, 5)))
                                        ->danger()
                                        ->send();
                                } else {
                                    Notification::make()
                                        ->title('Delete Processed')
                                        ->body("{$successCount} user(s) processed for deletion from {$device->name}.")
                                        ->success()
                                        ->send();
                                }
                            }
                        })
                        ->deselectRecordsAfterCompletion(),
                    BulkAction::make('assignCategory')
                        ->label('Assign Category')
                        ->icon('heroicon-o-tag')
                        ->form([
                            Select::make('branch_id')
                                ->label('Branch')
                                ->options(Branch::all()->pluck('display_name', 'id'))
                                ->default(fn () => Branch::count() === 1 ? Branch::first()->id : null)
                                ->live()
                                ->nullable(),
                            Select::make('department_id')
                                ->label('Department')
                                ->options(function ($get) {
                                    $branchId = $get('branch_id');
                                    if (! $branchId) {
                                        return Department::pluck('name', 'id');
                                    }

                                    return Department::whereHas('branches', fn ($q) => $q->where('branches.id', $branchId))->pluck('name', 'id');
                                })
                                ->default(fn () => Department::count() === 1 ? Department::first()->id : null)
                                ->nullable(),
                            Select::make('group')
                                ->label('Designation / Group')
                                ->options(User::whereNotNull('group')->where('group', '!=', '')->distinct()->pluck('group', 'group'))
                                ->searchable()
                                ->createOptionForm([
                                    TextInput::make('name')->required()->label('Name'),
                                ])
                                ->createOptionUsing(fn (array $data) => $data['name'])
                                ->nullable(),
                            Select::make('taskGroups')
                                ->label('Task Groups')
                                ->multiple()
                                ->options(TaskGroup::pluck('name', 'id'))
                                ->searchable()
                                ->default(fn () => TaskGroup::count() === 1 ? [TaskGroup::first()->id] : [])
                                ->createOptionForm([
                                    TextInput::make('name')->required(),
                                    Textarea::make('description'),
                                ])
                                ->createOptionUsing(function (array $data) {
                                    $taskGroup = TaskGroup::create($data);

                                    return $taskGroup->id;
                                })
                                ->nullable(),
                        ])
                        ->action(function (Collection $records, array $data) {
                            $updateData = [];
                            if (array_key_exists('branch_id', $data) && $data['branch_id'] !== null) {
                                $updateData['branch_id'] = $data['branch_id'];
                            }
                            if (array_key_exists('department_id', $data) && $data['department_id'] !== null) {
                                $updateData['department_id'] = $data['department_id'];
                            }
                            if (array_key_exists('group', $data) && $data['group'] !== null) {
                                $updateData['group'] = $data['group'];
                            }

                            if (! empty($updateData)) {
                                foreach ($records as $record) {
                                    $record->update($updateData);
                                }
                            }

                            if (! empty($data['taskGroups'])) {
                                foreach ($records as $record) {
                                    $record->taskGroups()->syncWithoutDetaching($data['taskGroups']);
                                }
                            }

                            Notification::make()
                                ->title('Success')
                                ->body('Categories assigned to selected users.')
                                ->success()
                                ->send();
                        })
                        ->deselectRecordsAfterCompletion(),
                ]),
            ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListUsers::route('/'),
            'create' => Pages\CreateUser::route('/create'),
            'view' => Pages\ViewUser::route('/{record}'),
            'edit' => Pages\EditUser::route('/{record}/edit'),
        ];
    }
}
