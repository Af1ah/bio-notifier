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
use App\Models\PayrollTemplate;
use App\Models\User;
use App\Services\Payroll\EmployeePayrollEditorService;
use App\Services\Attendance\ShiftAssignmentResolver;
use App\Services\Attendance\ShiftAssignmentService;
use App\Services\Devices\DeviceIntegration;
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
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;

class UserResource extends Resource
{
    protected static ?string $model = User::class;

    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-users';

    protected static ?int $navigationSort = 4;

    protected static ?string $modelLabel = 'Employee';

    protected static ?string $recordTitleAttribute = 'name';

    public static function getEloquentQuery(): Builder
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

    protected static ?string $pluralModelLabel = 'Employees';

    public static function getNavigationGroup(): ?string
    {
        return null;
    }

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            Section::make('Employee Information')
                ->schema([
                    TextInput::make('pin')
                        ->label('Employee ID (PIN)')
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
                    Select::make('device_privilege')
                        ->visible(fn (): bool => DeviceIntegration::usesEbio())
                        ->label('Device privilege')
                        ->options([
                            0 => 'Normal User',
                            14 => 'Device Admin',
                        ])
                        ->default(0)
                        ->helperText('Controls privileges on biometric devices.'),
                    TextInput::make('device_password')
                        ->visible(fn (): bool => DeviceIntegration::usesEbio())
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
            Section::make('Organisation details')
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
                    TextInput::make('religion')->maxLength(80)->helperText('Optional. Used only for holiday eligibility filters.'),
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
                ->description('Enable payroll only when this employee should receive salary slips. Disabled employees do not get a payroll profile or default pay values.')
                ->relationship('payrollProfile')
                ->saveRelationshipsBeforeChildrenUsing(function (Section $component): void {
                    $employee = $component->getRecord();
                    app(EmployeePayrollEditorService::class)->save($employee, $component->getChildSchema()->getState(shouldCallHooksBefore: false), auth()->user());
                    $component->cachedExistingRecord($employee->payrollProfile()->first());
                })
                ->schema([
                    Toggle::make('payroll_enabled')
                        ->label('Include in payroll')
                        ->helperText('Only enabled employees are included when salary slips are generated.')
                        ->default(false)
                        ->live(),
                    Select::make('payroll_template_id')
                        ->label('Payroll template')
                        ->options(fn () => PayrollTemplate::query()->orderBy('name')->pluck('name', 'id'))
                        ->searchable()
                        ->placeholder('Individual pay settings')
                        ->live()
                        ->visible(fn ($get) => (bool) $get('payroll_enabled')),
                    Select::make('template_overrides')
                        ->label('Keep this employee’s own amounts')
                        ->multiple()
                        ->options(['basic_salary_minor' => 'Base pay', 'housing_allowance_minor' => 'Housing allowance', 'transport_allowance_minor' => 'Transport allowance', 'other_allowance_minor' => 'Other allowance', 'fixed_deduction_minor' => 'Fixed deduction', 'overtime_hourly_rate_minor' => 'Overtime hourly rate'])
                        ->visible(fn ($get) => (bool) $get('payroll_enabled') && filled($get('payroll_template_id'))),
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
                        ->required(fn ($get) => (bool) $get('payroll_enabled'))
                        ->visible(fn ($get) => (bool) $get('payroll_enabled')),
                    Select::make('currency')
                        ->options(['INR' => 'INR'])
                        ->default('INR')
                        ->required(fn ($get) => (bool) $get('payroll_enabled'))
                        ->visible(fn ($get) => (bool) $get('payroll_enabled')),
                    DatePicker::make('effective_from')
                        ->label('Salary effective from')
                        ->native(false)
                        ->default(today())
                        ->visible(fn ($get) => (bool) $get('payroll_enabled')),
                    DatePicker::make('employment_starts_on')->label('Employment starts on')->native(false)->visible(fn ($get) => (bool) $get('payroll_enabled')),
                    DatePicker::make('employment_ends_on')->label('Employment ends on')->native(false)->afterOrEqual('employment_starts_on')->helperText('Monthly salary and recurring amounts are prorated by eligible calendar days.')->visible(fn ($get) => (bool) $get('payroll_enabled')),
                    self::moneyInput('basic_salary_minor', fn ($get): string => match ($get('pay_basis') ?? 'monthly') {
                        'daily' => 'Daily rate',
                        'hourly' => 'Hourly rate',
                        default => 'Basic monthly salary',
                    })->required(fn ($get) => (bool) $get('payroll_enabled'))->visible(fn ($get) => (bool) $get('payroll_enabled')),
                    self::moneyInput('housing_allowance_minor', 'Housing allowance')
                        ->visible(fn ($get): bool => (bool) $get('payroll_enabled') && ($get('pay_basis') ?? 'monthly') === 'monthly'),
                    self::moneyInput('transport_allowance_minor', 'Transport allowance')
                        ->visible(fn ($get): bool => (bool) $get('payroll_enabled') && ($get('pay_basis') ?? 'monthly') === 'monthly'),
                    self::moneyInput('other_allowance_minor', 'Other allowance')
                        ->visible(fn ($get): bool => (bool) $get('payroll_enabled') && ($get('pay_basis') ?? 'monthly') === 'monthly'),
                    self::moneyInput('fixed_deduction_minor', 'Fixed monthly deduction')
                        ->visible(fn ($get): bool => (bool) $get('payroll_enabled') && ($get('pay_basis') ?? 'monthly') === 'monthly'),
                    self::moneyInput('overtime_hourly_rate_minor', 'Overtime hourly rate')
                        ->helperText('For hourly employees, leave this at 0 to pay approved overtime at the regular hourly rate.')
                        ->visible(fn ($get) => (bool) $get('payroll_enabled')),
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
                    Section::make('Employee Details')
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
                                ->visible(fn (): bool => DeviceIntegration::usesEbio())
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
                                ->visible(fn (): bool => DeviceIntegration::usesEbio())
                                ->label('Face Template Saved')
                                ->badge()
                                ->formatStateUsing(fn ($state): string => match ($state) {
                                    true, 1 => 'Yes',
                                    false, 0 => 'No',
                                    default => 'Unknown',
                                })
                                ->color(fn ($state): string => $state ? 'success' : ($state === false ? 'gray' : 'warning')),
                            TextEntry::make('device_verification_type')
                                ->visible(fn (): bool => DeviceIntegration::usesEbio())
                                ->label('Verification type')
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
                Tables\Columns\TextColumn::make('device_privilege')
                    ->visible(fn (): bool => DeviceIntegration::usesEbio())
                    ->label('Device Privilege')
                    ->badge()
                    ->formatStateUsing(fn ($state) => (int) $state === 14 ? 'Device Admin' : 'Normal User')
                    ->color(fn ($state): string => (int) $state === 14 ? 'warning' : 'gray')
                    ->toggleable(isToggledHiddenByDefault: true),
                Tables\Columns\IconColumn::make('face_enrolled')
                    ->visible(fn (): bool => DeviceIntegration::usesEbio())
                    ->label('Face Template')
                    ->boolean()
                    ->placeholder('Unknown')
                    ->toggleable(),
                Tables\Columns\IconColumn::make('is_enabled')
                    ->boolean()
                    ->visibleFrom('md'),
            ])
            ->filters([
                Tables\Filters\SelectFilter::make('device_privilege')
                    ->visible(fn (): bool => DeviceIntegration::usesEbio())
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
                        ->authorize(fn (): bool => in_array(tenant()?->device_integration, [DeviceIntegration::EBIO, DeviceIntegration::GATEWAY], true))
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
                                    'finger' => 'Fingerprint (Template)',
                                    'face' => 'Face (Photo / Template)',
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
                            \Filament\Forms\Components\Textarea::make('template_data')
                                ->label(fn ($get) => $get('type') === 'finger' ? 'Base64 Fingerprint Template' : 'Base64 Photo / Face Vector')
                                ->helperText('Paste the Base64 template string or JPEG image data to sync to this terminal.')
                                ->visible(fn () => tenant()?->device_integration === DeviceIntegration::GATEWAY)
                                ->required(fn () => tenant()?->device_integration === DeviceIntegration::GATEWAY),
                        ])
                        ->action(function (User $record, array $data) {
                            $device = Device::find($data['device_id']);
                            if (! $device) {
                                return;
                            }

                            if (DeviceIntegration::usesEbio()) {
                                EnrollEbioBiometricJob::dispatch(
                                    tenancy()->tenant,
                                    $record->id,
                                    $data['device_id'],
                                    $data['type'],
                                    $data['finger_index'] ?? null
                                );
                            } else {
                                $commandService = app(\App\Services\Devices\DeviceCommandService::class);
                                $content = trim((string) ($data['template_data'] ?? ''));
                                if ($data['type'] === 'finger') {
                                    $commandService->request($device, 'update_biodata', [
                                        'pin' => (string) $record->pin,
                                        'no' => (string) ($data['finger_index'] ?? 0),
                                        'type' => '1',
                                        'tmp' => $content,
                                    ]);
                                } else {
                                    $commandService->request($device, 'update_biophoto', [
                                        'pin' => (string) $record->pin,
                                        'size' => (string) strlen($content),
                                        'content' => $content,
                                    ]);
                                }
                            }

                            Notification::make()
                                ->title('Biometric command queued')
                                ->body('The biometric synchronization command has been queued for sync.')
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
                        ->authorize(fn (): bool => DeviceIntegration::usesEbio())
                        ->label('Unblock employee from door')
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
                                ->body("{$count} employee(s) unblocked and queued for sync.")
                                ->success()
                                ->send();
                        })
                        ->deselectRecordsAfterCompletion(),
                    BulkAction::make('disableUsers')
                        ->authorize(fn (): bool => DeviceIntegration::usesEbio())
                        ->label('Block employee from door')
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
                                ->body("{$count} employee(s) blocked and queued for sync.")
                                ->success()
                                ->send();
                        })
                        ->deselectRecordsAfterCompletion(),
                    BulkAction::make('pushToDevice')
                        ->authorize(fn (): bool => in_array(tenant()?->device_integration, [DeviceIntegration::EBIO, DeviceIntegration::GATEWAY], true))
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
                                        $label = $d->name ?: $d->serial_number;
                                        $loc = $d->options['location'] ?? null;
                                        if ($loc) {
                                            $label .= " (Location: $loc)";
                                        }
                                        $options[$d->id] = $label;
                                    }

                                    return $options;
                                })
                                ->searchable()
                                ->multiple()
                                ->placeholder('Leave blank for all devices'),
                        ])
                        ->action(function (Collection $records, array $data) {
                            $organisation = tenancy()->tenant;
                            $targetDeviceIds = ! empty($data['location']) ? (array) $data['location'] : [];
                            $devices = Device::query()
                                ->when(! empty($targetDeviceIds), fn ($q) => $q->whereIn('id', $targetDeviceIds))
                                ->get();

                            if (DeviceIntegration::usesEbio()) {
                                $location = '';
                                if (! empty($data['location'])) {
                                    $location = is_array($data['location']) ? implode(',', $data['location']) : $data['location'];
                                }
                                $count = 0;
                                foreach ($records as $user) {
                                    PushEbioUserJob::dispatch($organisation, $user->id, $location);
                                    $count++;
                                }
                            } else {
                                $commandService = app(\App\Services\Devices\DeviceCommandService::class);
                                $count = 0;
                                foreach ($devices as $device) {
                                    foreach ($records as $user) {
                                        try {
                                            $commandService->request($device, 'upsert_user', [
                                                'pin' => (string) $user->pin,
                                                'name' => (string) ($user->name ?: $user->pin),
                                                'privilege' => (string) ($user->privilege ?? 0),
                                                'card' => (string) ($user->rfid ?? ''),
                                            ]);
                                            $count++;
                                        } catch (\Throwable $e) {
                                            report($e);
                                        }
                                    }
                                }
                            }

                            Notification::make()
                                ->title('Sync Queued')
                                ->body("{$records->count()} employee(s) queued for device synchronization.")
                                ->success()
                                ->send();
                        })
                        ->deselectRecordsAfterCompletion(),
                    BulkAction::make('deleteFromDevice')
                        ->authorize(fn (): bool => in_array(tenant()?->device_integration, [DeviceIntegration::EBIO, DeviceIntegration::GATEWAY], true))
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
                                        $label = $d->name ?: $d->serial_number;
                                        $loc = $d->options['location'] ?? null;
                                        if ($loc) {
                                            $label .= " (Location: $loc)";
                                        }
                                        $options[$d->id] = $label;
                                    }

                                    return $options;
                                })
                                ->searchable()
                                ->multiple()
                                ->placeholder('Leave blank for all devices'),
                        ])
                        ->action(function (Collection $records, array $data) {
                            $organisation = tenancy()->tenant;
                            $targetDeviceIds = ! empty($data['location']) ? (array) $data['location'] : [];
                            $devices = Device::query()
                                ->when(! empty($targetDeviceIds), fn ($q) => $q->whereIn('id', $targetDeviceIds))
                                ->get();

                            if (DeviceIntegration::usesEbio()) {
                                $location = '';
                                if (! empty($data['location'])) {
                                    $location = is_array($data['location']) ? implode(',', $data['location']) : $data['location'];
                                }
                                $count = 0;
                                foreach ($records as $record) {
                                    DeleteEbioUserJob::dispatch($organisation, $record->pin, $location);
                                    $count++;
                                }
                            } else {
                                $commandService = app(\App\Services\Devices\DeviceCommandService::class);
                                foreach ($devices as $device) {
                                    foreach ($records as $record) {
                                        try {
                                            $commandService->request($device, 'delete_user', [
                                                'pin' => (string) $record->pin,
                                            ]);
                                        } catch (\Throwable $e) {
                                            report($e);
                                        }
                                    }
                                }
                            }

                            Notification::make()
                                ->title('Deletion Queued')
                                ->body("{$records->count()} employee deletions queued.")
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
                                ->body("Selected employees were assigned to the {$label}.")
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
