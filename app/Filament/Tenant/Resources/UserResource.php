<?php

namespace App\Filament\Tenant\Resources;

use App\Filament\Tenant\Resources\UserResource\Pages;
use App\Jobs\BlockUnblockEbioUserJob;
use App\Jobs\DeleteEbioUserJob;
use App\Jobs\EnrollEbioBiometricJob;
use App\Jobs\PushEbioUserJob;
use App\Models\Branch;
use App\Models\Department;
use App\Models\Device;
use App\Models\Schedule;
use App\Models\TaskGroup;
use App\Models\User;
use App\Services\Attendance\ShiftAssignmentResolver;
use App\Services\Attendance\ShiftAssignmentService;
use Filament\Actions\Action;
use Filament\Actions\ActionGroup;
use Filament\Actions\BulkAction;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Actions\ViewAction;
use Filament\Forms\Components\DatePicker;
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

    protected static ?int $navigationSort = 4;

    protected static ?string $modelLabel = 'User';

    protected static ?string $recordTitleAttribute = 'name';

    public static function getEloquentQuery(): \Illuminate\Database\Eloquent\Builder
    {
        return parent::getEloquentQuery()->employees();
    }

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
        return null;
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
                        ->label('Application Access')
                        ->options([
                            0 => 'User',
                            14 => 'Admin',
                        ])
                        ->default(0)
                        ->helperText('Controls access to Secumax. Device sync never changes this field.'),
                    Select::make('device_privilege')
                        ->label('eBio Device Privilege')
                        ->options([
                            0 => 'Normal User',
                            14 => 'Device Admin',
                        ])
                        ->default(0)
                        ->helperText('Controls privileges on biometric devices.'),
                    TextInput::make('device_password')
                        ->label('Device Password (Numeric)')
                        ->numeric()
                        ->maxLength(8)
                        ->password()
                        ->revealable()
                        ->autocomplete('new-password'),
                    DatePicker::make('valid_from')
                        ->label('Valid From')
                        ->nullable(),
                    DatePicker::make('valid_to')
                        ->label('Valid To')
                        ->nullable(),
                    Toggle::make('is_enabled')
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
            Section::make('Payroll')
                ->description('Choose how this employee earns pay, then set the matching monthly, daily, or hourly base rate.')
                ->relationship('payrollProfile')
                ->schema([
                    Toggle::make('payroll_enabled')
                        ->label('Include in payroll')
                        ->helperText('Only enabled employees are included when salary slips are generated.')
                        ->default(false)
                        ->live(),
                    Select::make('pay_basis')
                        ->label('Pay basis')
                        ->options([
                            'monthly' => 'Monthly salary',
                            'daily' => 'Daily rate (pay for worked days)',
                            'hourly' => 'Hourly rate (pay for approved worked time)',
                        ])
                        ->default('monthly')
                        ->helperText('Monthly salary does not deduct holidays or weekly-offs. Daily and hourly employees are paid only for approved attendance.')
                        ->live()
                        ->required(),
                    Select::make('currency')
                        ->options(['INR' => 'INR'])
                        ->default('INR')
                        ->required(),
                    DatePicker::make('effective_from')
                        ->label('Salary effective from')
                        ->native(false)
                        ->default(today()),
                    self::moneyInput('basic_salary_minor', fn ($get): string => match ($get('pay_basis') ?? 'monthly') {
                        'daily' => 'Daily rate',
                        'hourly' => 'Hourly rate',
                        default => 'Basic monthly salary',
                    })->required(),
                    self::moneyInput('housing_allowance_minor', 'Housing allowance')
                        ->visible(fn ($get): bool => ($get('pay_basis') ?? 'monthly') === 'monthly'),
                    self::moneyInput('transport_allowance_minor', 'Transport allowance')
                        ->visible(fn ($get): bool => ($get('pay_basis') ?? 'monthly') === 'monthly'),
                    self::moneyInput('other_allowance_minor', 'Other allowance')
                        ->visible(fn ($get): bool => ($get('pay_basis') ?? 'monthly') === 'monthly'),
                    self::moneyInput('fixed_deduction_minor', 'Fixed monthly deduction')
                        ->visible(fn ($get): bool => ($get('pay_basis') ?? 'monthly') === 'monthly'),
                    self::moneyInput('overtime_hourly_rate_minor', 'Overtime hourly rate')
                        ->helperText('For hourly employees, leave this at 0 to pay approved overtime at the regular hourly rate.'),
                ])
                ->columns([
                    'default' => 1,
                    'md' => 2,
                    'xl' => 3,
                ])
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
                                ->state(function ($record) {
                                    $rawState = $record->fingerprints;
                                    if (empty($rawState) || ! is_array($rawState)) {
                                        return ['None'];
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

                                    return count($added) > 0 ? $added : [count($rawState).' Template(s)'];
                                })
                                ->color('success')
                                ->columnSpanFull(),
                            TextEntry::make('face_enrolled')
                                ->label('Face Template Saved')
                                ->badge()
                                ->formatStateUsing(fn ($state): string => match ($state) {
                                    true, 1 => 'Yes',
                                    false, 0 => 'No',
                                    default => 'Unknown',
                                })
                                ->color(fn ($state): string => $state ? 'success' : ($state === false ? 'gray' : 'warning')),
                            TextEntry::make('device_verification_type')
                                ->label('eBio Verification Type')
                                ->default('Not reported'),
                        ])->columns(['default' => 2, 'sm' => 2, 'md' => 2]),

                    Section::make('Shift Details')
                        ->schema([
                            TextEntry::make('shift')
                                ->label('Active Shift')
                                ->state(function ($record) {
                                    $assignment = app(ShiftAssignmentResolver::class)->resolve($record, now());
                                    if (! $assignment) {
                                        return 'No Active Schedule';
                                    }

                                    return $assignment->slots->map(function ($slot) {
                                        $rule = $slot->ruleRevision;

                                        return $slot->schedule->name.' ('.substr($rule?->start_time ?? '--:--', 0, 5).'–'.substr($rule?->end_time ?? '--:--', 0, 5).')';
                                    })->join(', ');
                                }),
                        ]),
                    Section::make('Payroll')
                        ->schema([
                            TextEntry::make('payrollProfile.payroll_enabled')
                                ->label('Payroll status')
                                ->formatStateUsing(fn ($state): string => $state ? 'Enabled' : 'Disabled')
                                ->badge()
                                ->color(fn ($state): string => $state ? 'success' : 'gray'),
                            TextEntry::make('payrollProfile.basic_salary_minor')
                                ->label('Basic salary')
                                ->formatStateUsing(fn ($state, $record): string => self::formatMoney($state, $record->payrollProfile?->currency)),
                            TextEntry::make('payrollProfile.housing_allowance_minor')
                                ->label('Housing allowance')
                                ->formatStateUsing(fn ($state, $record): string => self::formatMoney($state, $record->payrollProfile?->currency)),
                            TextEntry::make('payrollProfile.transport_allowance_minor')
                                ->label('Transport allowance')
                                ->formatStateUsing(fn ($state, $record): string => self::formatMoney($state, $record->payrollProfile?->currency)),
                            TextEntry::make('payrollProfile.other_allowance_minor')
                                ->label('Other allowance')
                                ->formatStateUsing(fn ($state, $record): string => self::formatMoney($state, $record->payrollProfile?->currency)),
                            TextEntry::make('payrollProfile.fixed_deduction_minor')
                                ->label('Fixed deduction')
                                ->formatStateUsing(fn ($state, $record): string => self::formatMoney($state, $record->payrollProfile?->currency)),
                            TextEntry::make('payrollProfile.overtime_hourly_rate_minor')
                                ->label('Overtime hourly rate')
                                ->formatStateUsing(fn ($state, $record): string => self::formatMoney($state, $record->payrollProfile?->currency)),
                        ])
                        ->columns([
                            'default' => 1,
                            'md' => 2,
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

    private static function moneyInput(string $name, string|\Closure $label): TextInput
    {
        return TextInput::make($name)
            ->label($label)
            ->prefix('₹')
            ->numeric()
            ->minValue(0)
            ->step(0.01)
            ->default(0)
            ->formatStateUsing(fn ($state): string => number_format(((int) ($state ?? 0)) / 100, 2, '.', ''))
            ->dehydrateStateUsing(fn ($state): int => (int) round(((float) $state) * 100));
    }

    private static function formatMoney($minor, ?string $currency): string
    {
        return ($currency ?? 'INR').' '.number_format(((int) ($minor ?? 0)) / 100, 2);
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
                    ->toggleable()
                    ->visibleFrom('md'),
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
                    ->label('Application Access')
                    ->badge()
                    ->formatStateUsing(fn ($state) => $state === 14 ? 'Admin' : 'User')
                    ->color(fn ($state): string => $state === 14 ? 'primary' : 'gray')
                    ->visibleFrom('md'),
                Tables\Columns\TextColumn::make('device_privilege')
                    ->label('Device Privilege')
                    ->badge()
                    ->formatStateUsing(fn ($state) => (int) $state === 14 ? 'Device Admin' : 'Normal User')
                    ->color(fn ($state): string => (int) $state === 14 ? 'warning' : 'gray')
                    ->toggleable(isToggledHiddenByDefault: true),
                Tables\Columns\IconColumn::make('face_enrolled')
                    ->label('Face Template')
                    ->boolean()
                    ->placeholder('Unknown')
                    ->toggleable(),
                Tables\Columns\IconColumn::make('is_enabled')
                    ->boolean()
                    ->visibleFrom('md'),
            ])
            ->filters([
                Tables\Filters\SelectFilter::make('privilege')
                    ->label('Application Access')
                    ->options([
                        0 => 'User',
                        14 => 'Admin',
                    ]),
                Tables\Filters\SelectFilter::make('device_privilege')
                    ->label('Device Privilege')
                    ->options([
                        0 => 'Normal User',
                        14 => 'Device Admin',
                    ]),
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
                    Action::make('addBiometric')
                        ->label('Add Biometric')
                        ->icon('heroicon-o-finger-print')
                        ->form([
                            Select::make('device_id')
                                ->label('Select Device')
                                ->options(function () {
                                    return Device::all()->mapWithKeys(function ($d) {
                                        return [$d->id => $d->name ?: $d->serial_number];
                                    })->toArray();
                                })
                                ->required()
                                ->searchable(),
                            Select::make('type')
                                ->label('Biometric Type')
                                ->options([
                                    'finger' => 'Fingerprint',
                                    'face' => 'Face',
                                ])
                                ->required()
                                ->live(),
                            Select::make('finger_index')
                                ->label('Select Finger')
                                ->options([
                                    0 => '0 - Left Pinky',
                                    1 => '1 - Left Ring',
                                    2 => '2 - Left Middle',
                                    3 => '3 - Left Index',
                                    4 => '4 - Left Thumb',
                                    5 => '5 - Right Thumb',
                                    6 => '6 - Right Index',
                                    7 => '7 - Right Middle',
                                    8 => '8 - Right Ring',
                                    9 => '9 - Right Pinky',
                                ])
                                ->visible(fn ($get) => $get('type') === 'finger')
                                ->required(fn ($get) => $get('type') === 'finger'),
                        ])
                        ->action(function (User $record, array $data) {
                            EnrollEbioBiometricJob::dispatch(
                                tenancy()->tenant,
                                $record->id,
                                $data['device_id'],
                                $data['type'],
                                $data['finger_index'] ?? null
                            );
                            Notification::make()
                                ->title('Enrollment command queued')
                                ->success()
                                ->send();
                        }),
                    ViewAction::make(),
                    EditAction::make(),
                ]),
            ])
            ->bulkActions([
                BulkActionGroup::make([
                    DeleteBulkAction::make(),
                    BulkAction::make('enableUsers')
                        ->label('Unblock user from door')
                        ->icon('heroicon-o-check-circle')
                        ->color('success')
                        ->form([
                            Select::make('location')
                                ->label('Select Device(s)')
                                ->options(function () {
                                    return Device::all()->mapWithKeys(function ($d) {
                                        $loc = $d->options['location'] ?? null;

                                        return $loc ? [$loc => ($d->name ?: $d->serial_number)." (Location: $loc)"] : [];
                                    })->filter()->toArray();
                                })
                                ->searchable()
                                ->multiple()
                                ->placeholder('Leave blank for all devices'),
                        ])
                        ->action(function (Collection $records, array $data) {
                            $location = ! empty($data['location']) ? (is_array($data['location']) ? implode(',', $data['location']) : $data['location']) : '';
                            $organisation = tenancy()->tenant;
                            $count = 0;
                            foreach ($records as $record) {
                                BlockUnblockEbioUserJob::dispatch($organisation, $record->id, $location, false); // false = Unblock
                                $count++;
                            }
                            Notification::make()
                                ->title('Unblocked and Queued')
                                ->body("{$count} user(s) unblocked and queued for sync.")
                                ->success()
                                ->send();
                        })
                        ->deselectRecordsAfterCompletion(),
                    BulkAction::make('disableUsers')
                        ->label('Block user from door')
                        ->icon('heroicon-o-x-circle')
                        ->color('warning')
                        ->form([
                            Select::make('location')
                                ->label('Select Device(s)')
                                ->options(function () {
                                    return Device::all()->mapWithKeys(function ($d) {
                                        $loc = $d->options['location'] ?? null;

                                        return $loc ? [$loc => ($d->name ?: $d->serial_number)." (Location: $loc)"] : [];
                                    })->filter()->toArray();
                                })
                                ->searchable()
                                ->multiple()
                                ->placeholder('Leave blank for all devices'),
                        ])
                        ->action(function (Collection $records, array $data) {
                            $location = ! empty($data['location']) ? (is_array($data['location']) ? implode(',', $data['location']) : $data['location']) : '';
                            $organisation = tenancy()->tenant;
                            $count = 0;
                            foreach ($records as $record) {
                                BlockUnblockEbioUserJob::dispatch($organisation, $record->id, $location, true); // true = Block
                                $count++;
                            }
                            Notification::make()
                                ->title('Blocked and Queued')
                                ->body("{$count} user(s) blocked and queued for sync.")
                                ->success()
                                ->send();
                        })
                        ->deselectRecordsAfterCompletion(),
                    BulkAction::make('pushToDevice')
                        ->icon('heroicon-o-arrow-up-on-square')
                        ->color('success')
                        ->label('Push to Device / Location')
                        ->form([
                            Select::make('location')
                                ->label('Select Device(s)')
                                ->options(function () {
                                    $devices = Device::all();
                                    $options = [];
                                    foreach ($devices as $d) {
                                        $loc = $d->options['location'] ?? null;
                                        if ($loc) {
                                            $label = ($d->name ?: $d->serial_number)." (Location: $loc)";
                                            $options[$loc] = $label;
                                        }
                                    }

                                    return $options;
                                })
                                ->searchable()
                                ->multiple()
                                ->placeholder('Leave blank for all devices'),
                        ])
                        ->action(function (Collection $records, array $data) {
                            $location = '';
                            if (! empty($data['location'])) {
                                $location = is_array($data['location']) ? implode(',', $data['location']) : $data['location'];
                            }

                            $organisation = tenancy()->tenant;
                            $count = 0;

                            foreach ($records as $user) {
                                PushEbioUserJob::dispatch($organisation, $user->id, $location);
                                $count++;
                            }

                            Notification::make()
                                ->title('Sync Queued')
                                ->body("{$count} user(s) queued for sync to eBioServer.")
                                ->success()
                                ->send();
                        })
                        ->deselectRecordsAfterCompletion(),
                    BulkAction::make('deleteFromDevice')
                        ->icon('heroicon-o-trash')
                        ->color('danger')
                        ->label('Delete from Devices')
                        ->requiresConfirmation()
                        ->form([
                            Select::make('location')
                                ->label('Select Device(s)')
                                ->options(function () {
                                    $devices = Device::all();
                                    $options = [];
                                    foreach ($devices as $d) {
                                        $loc = $d->options['location'] ?? null;
                                        if ($loc) {
                                            $label = ($d->name ?: $d->serial_number)." (Location: $loc)";
                                            $options[$loc] = $label;
                                        }
                                    }

                                    return $options;
                                })
                                ->searchable()
                                ->multiple()
                                ->placeholder('Leave blank for all devices'),
                        ])
                        ->action(function (Collection $records, array $data) {
                            $location = '';
                            if (! empty($data['location'])) {
                                $location = is_array($data['location']) ? implode(',', $data['location']) : $data['location'];
                            }

                            $organisation = tenancy()->tenant;
                            $count = 0;

                            foreach ($records as $record) {
                                DeleteEbioUserJob::dispatch($organisation, $record->pin, $location);
                                $count++;
                            }

                            Notification::make()
                                ->title('Deletion Queued')
                                ->body("{$count} user deletions queued.")
                                ->success()
                                ->send();
                        })
                        ->deselectRecordsAfterCompletion(),
                    BulkAction::make('assignShift')
                        ->label('Assign shift')
                        ->icon('heroicon-o-clock')
                        ->form([
                            Select::make('schedule_ids')->label('Shift')->options(fn () => Schedule::where('status', true)->pluck('name', 'id'))->multiple()->minItems(1)->required()->searchable()->preload()->live(),
                            Select::make('daily_policy_schedule_id')->label('Daily rules source')->options(fn () => Schedule::where('status', true)->pluck('name', 'id'))->visible(fn ($get) => count($get('schedule_ids') ?? []) > 1)->required(fn ($get) => count($get('schedule_ids') ?? []) > 1),
                            DatePicker::make('effective_from')->required()->default(today()),
                            DatePicker::make('effective_to')->rule('after_or_equal:effective_from'),
                        ])->action(function (Collection $records, array $data) {
                            app(ShiftAssignmentService::class)->assign('user', $records, $data);
                            Notification::make()->title('Shift assigned')->body($records->count().' employees updated')->success()->send();
                        })->deselectRecordsAfterCompletion(),
                    BulkAction::make('assignOrganisation')
                        ->label('Assign Branch, Department, or Task Group')
                        ->icon('heroicon-o-tag')
                        ->form([
                            Select::make('assignment_type')
                                ->label('Assign to')
                                ->options([
                                    'branch' => 'Branch',
                                    'department' => 'Department',
                                    'task_group' => 'Task Group',
                                ])
                                ->required()
                                ->live()
                                ->afterStateUpdated(fn (callable $set) => $set('assignment_id', null)),
                            Select::make('assignment_id')
                                ->label(fn (callable $get): string => match ($get('assignment_type')) {
                                    'branch' => 'Branch',
                                    'department' => 'Department',
                                    'task_group' => 'Task Group',
                                    default => 'Select an assignment type first',
                                })
                                ->options(fn (callable $get): array => match ($get('assignment_type')) {
                                    'branch' => Branch::query()
                                        ->get()
                                        ->mapWithKeys(fn (Branch $branch) => [$branch->id => $branch->display_name])
                                        ->all(),
                                    'department' => Department::query()->pluck('name', 'id')->all(),
                                    'task_group' => TaskGroup::query()->pluck('name', 'id')->all(),
                                    default => [],
                                })
                                ->required()
                                ->searchable()
                                ->disabled(fn (callable $get): bool => blank($get('assignment_type'))),
                        ])
                        ->action(function (Collection $records, array $data) {
                            foreach ($records as $record) {
                                match ($data['assignment_type']) {
                                    'branch' => $record->update(['branch_id' => $data['assignment_id']]),
                                    'department' => $record->update(['department_id' => $data['assignment_id']]),
                                    'task_group' => $record->taskGroups()->syncWithoutDetaching([$data['assignment_id']]),
                                };
                            }

                            $label = match ($data['assignment_type']) {
                                'branch' => 'branch',
                                'department' => 'department',
                                'task_group' => 'task group',
                            };

                            Notification::make()
                                ->title('Assignment saved')
                                ->body("Selected users were assigned to the {$label}.")
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
