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
use App\Models\DeviceCommand;
use App\Models\Schedule;
use App\Models\TaskGroup;
use App\Models\PayrollTemplate;
use App\Models\User;
use App\Services\Payroll\EmployeePayrollEditorService;
use App\Services\Attendance\ShiftAssignmentResolver;
use App\Services\Attendance\ShiftAssignmentService;
use App\Services\Devices\DeviceIntegration;
use App\Services\Devices\EmployeeBiometrics;
use Filament\Actions\Action;
use Filament\Actions\ActionGroup;
use Filament\Actions\BulkAction;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Actions\ViewAction;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\CheckboxList;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\Radio;
use Filament\Forms\Components\ViewField;
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
use Illuminate\Support\Facades\Storage;

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
                        ->visible(fn (): bool => in_array(tenant()?->device_integration, [DeviceIntegration::EBIO, DeviceIntegration::GATEWAY], true))
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
                                ->visible(fn (): bool => in_array(tenant()?->device_integration, [DeviceIntegration::EBIO, DeviceIntegration::GATEWAY], true))
                                ->label('Stored fingerprint templates')
                                ->state(fn (User $record): string => implode(', ', self::storedFingerprintLabels($record)) ?: 'None stored')
                                ->columnSpanFull(),
                            TextEntry::make('face_templates')
                                ->visible(fn (): bool => in_array(tenant()?->device_integration, [DeviceIntegration::EBIO, DeviceIntegration::GATEWAY], true))
                                ->label('Stored face templates')
                                ->state(fn (User $record): string => ($count = count(self::storedFaceTemplates($record))) > 0 ? "{$count} stored" : 'None stored'),
                            TextEntry::make('ai_face_photo_path')
                                ->visible(fn (): bool => tenant()?->device_integration === DeviceIntegration::GATEWAY)
                                ->label('Stored AI Face JPEG')
                                ->state(fn (User $record): string => $record->ai_face_photo_path && Storage::disk('local')->exists($record->ai_face_photo_path) ? 'Stored' : 'None stored'),
                            TextEntry::make('biometric_transfer_status')
                                ->visible(fn (): bool => tenant()?->device_integration === DeviceIntegration::GATEWAY)
                                ->label('Latest biometric transfer')
                                ->state(fn (User $record): string => self::latestBiometricTransfer($record)),
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
                    Action::make('pushToDevice')
                        ->authorize(fn (): bool => tenant()?->device_integration === DeviceIntegration::GATEWAY)
                        ->label('Push to device')
                        ->icon('heroicon-o-arrow-up-on-square')
                        ->color('success')
                        ->modalWidth('lg')
                        ->modalDescription('Send the employee and selected stored credentials. Available formats follow the device settings.')
                        ->form([
                            Select::make('device_id')->label('Device')
                                ->options(fn () => self::deviceOptions('upsert_user'))
                                ->searchable()->required()->live()
                                ->afterStateUpdated(fn ($set, $state) => $set('formats', Device::find($state)?->supportsVerificationFormat('rfid') ? ['rfid'] : [])),
                            CheckboxList::make('formats')->label('Include credentials')
                                ->options(fn ($get): array => ($device = Device::find($get('device_id'))) ? app(EmployeeBiometrics::class)->formats($device) : [])
                                ->default(['rfid'])
                                ->helperText('Card is selected by default. Fingerprint sends all stored fingers; Face sends stored face templates; AI Face sends the stored JPEG. Unselected credentials are left unchanged.')
                                ->visible(fn ($get): bool => filled($get('device_id'))),
                        ])
                        ->action(function (User $record, array $data): void {
                            $count = app(EmployeeBiometrics::class)->push($record, Device::findOrFail($data['device_id']), $data['formats'] ?? []);
                            Notification::make()->title('Device update queued')
                                ->body("{$count} command(s) queued. Track their results in Device Commands.")->success()->send();
                        }),
                    Action::make('fetchBiometric')
                        ->authorize(fn (): bool => tenant()?->device_integration === DeviceIntegration::GATEWAY)
                        ->label('Fetch biometric templates')
                        ->icon('heroicon-o-arrow-down-tray')
                        ->modalWidth('lg')
                        ->modalDescription('Refresh the employee’s stored templates from a device. Missing templates are also fetched automatically after the first biometric punch.')
                        ->form([
                            Select::make('device_id')->label('Source device')
                                ->options(fn (): array => self::biometricFetchDeviceOptions())->searchable()->required(),
                        ])
                        ->action(function (User $record, array $data): void {
                            app(EmployeeBiometrics::class)->fetch($record, Device::findOrFail($data['device_id']));
                            Notification::make()->title('Biometric read queued')->body('The profile updates when the device returns its templates.')->success()->send();
                        }),
                    Action::make('registerBiometric')
                        ->authorize(fn (): bool => tenant()?->device_integration === DeviceIntegration::GATEWAY)
                        ->label('Register biometric')
                        ->icon('heroicon-o-finger-print')
                        ->modalWidth('lg')
                        ->modalDescription('Save biometric data to this employee, then use Push to device to transfer it.')
                        ->form([
                            Select::make('source')->label('Input')
                                ->options(['device' => 'Read from attendance device', 'finger' => 'Raw fingerprint template', 'ai_face' => 'AI Face JPEG'])
                                ->required()->live(),
                            Select::make('device_id')->label('Source device')
                                ->options(fn (): array => self::biometricFetchDeviceOptions())
                                ->helperText('Enroll the employee on this device first. Reads its enabled fingerprint and face template formats.')
                                ->visible(fn ($get): bool => $get('source') === 'device')
                                ->required(fn ($get): bool => $get('source') === 'device')->searchable(),
                            Select::make('finger_index')->label('Finger slot')
                                ->options(collect(range(0, 9))->mapWithKeys(fn ($slot) => [$slot => self::fingerName((string) $slot)])->all())
                                ->visible(fn ($get): bool => $get('source') === 'finger')
                                ->required(fn ($get): bool => $get('source') === 'finger'),
                            Textarea::make('template_data')->label('Raw fingerprint template')
                                ->helperText('Paste the template produced by your compatible fingerprint reader or enrollment software. A fingerprint image cannot be used here.')
                                ->rows(4)->maxLength(8192)
                                ->visible(fn ($get): bool => $get('source') === 'finger')
                                ->required(fn ($get): bool => $get('source') === 'finger'),
                            Radio::make('photo_input')->label('Photo source')
                                ->options(['upload' => 'Upload photo', 'camera' => 'Take photo'])->default('upload')->inline()->live()
                                ->visible(fn ($get): bool => $get('source') === 'ai_face'),
                            ViewField::make('camera_photo')->label('Camera')
                                ->view('filament.forms.ai-face-camera')->rules(['nullable', 'string', 'max:1048600'])
                                ->visible(fn ($get): bool => $get('source') === 'ai_face' && $get('photo_input') === 'camera')
                                ->required(fn ($get): bool => $get('source') === 'ai_face' && $get('photo_input') === 'camera'),
                            FileUpload::make('ai_face_photo_path')->label('AI Face JPEG')
                                ->disk('local')->visibility('private')->directory('biometric-photos')
                                ->acceptedFileTypes(['image/jpeg'])->maxSize(768)
                                ->visible(fn ($get): bool => $get('source') === 'ai_face' && $get('photo_input') !== 'camera')
                                ->required(fn ($get): bool => $get('source') === 'ai_face' && $get('photo_input') !== 'camera'),
                        ])
                        ->action(function (User $record, array $data): void {
                            $service = app(EmployeeBiometrics::class);
                            match ($data['source']) {
                                'device' => $service->fetch($record, Device::findOrFail($data['device_id'])),
                                'finger' => $service->registerFingerprint($record, (string) $data['finger_index'], (string) $data['template_data']),
                                'ai_face' => ($data['photo_input'] ?? 'upload') === 'camera'
                                    ? $service->registerCameraPhoto($record, (string) $data['camera_photo'])
                                    : $service->registerPhoto($record, (string) $data['ai_face_photo_path']),
                                default => throw \Illuminate\Validation\ValidationException::withMessages(['source' => 'Choose a supported input.']),
                            };
                            Notification::make()->title($data['source'] === 'device' ? 'Biometric read queued' : 'Biometric saved')
                                ->body($data['source'] === 'device' ? 'The employee profile updates when the device returns its templates.' : 'Use Push to device to send the saved data to a compatible device.')
                                ->success()->send();
                        }),
                    Action::make('addBiometric')
                        ->authorize(fn (): bool => DeviceIntegration::usesEbio())
                        ->label('Register biometric')
                        ->icon('heroicon-o-finger-print')
                        ->form([
                            Select::make('device_id')->label('Device')
                                ->options(fn () => Device::all()->mapWithKeys(fn (Device $device) => [$device->id => $device->name ?: $device->serial_number])->all())
                                ->required()->searchable(),
                            Select::make('type')->label('Biometric type')
                                ->options(['finger' => 'Fingerprint', 'ai_face' => 'AI Face'])->required()->live(),
                            Select::make('finger_index')->label('Finger slot')
                                ->options(collect(range(0, 9))->mapWithKeys(fn ($slot) => [$slot => self::fingerName((string) $slot)])->all())
                                ->visible(fn ($get): bool => $get('type') === 'finger')
                                ->required(fn ($get): bool => $get('type') === 'finger'),
                        ])
                        ->action(function (User $record, array $data): void {
                            EnrollEbioBiometricJob::dispatch(tenancy()->tenant, $record->id, $data['device_id'], $data['type'], $data['finger_index'] ?? null);
                            Notification::make()->title('Biometric registration queued')->success()->send();
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
                        ->label(fn (): string => DeviceIntegration::usesEbio() ? 'Push to Device / Location' : 'Add or update on device')
                        ->modalDescription(fn (): string => DeviceIntegration::usesEbio()
                            ? 'Send the selected employees to the selected eBio locations.'
                            : 'Send the selected employees to the selected devices. Existing device users with the same PIN are updated.')
                        ->form([
                            Select::make('location')
                                ->label('Select Device(s)')
                                ->options(fn () => self::deviceOptions(DeviceIntegration::usesEbio() ? null : 'upsert_user'))
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
                                $devices = $devices->filter(fn (Device $device) => $commandService->available($device, 'upsert_user'));
                                $count = 0;
                                $skipped = 0;
                                foreach ($devices as $device) {
                                    foreach ($records as $user) {
                                        try {
                                            $commandService->request($device, 'upsert_user', [
                                                'pin' => (string) $user->pin,
                                                'name' => (string) ($user->name ?: $user->pin),
                                                'privilege' => (string) ((int) $user->device_privilege === 14 ? 14 : 0),
                                                'card' => (string) ($user->card_number ?? ''),
                                            ]);
                                            $count++;
                                        } catch (\Throwable $e) {
                                            report($e);
                                            $skipped++;
                                        }
                                    }
                                }
                                if ($count === 0) {
                                    Notification::make()->title('No compatible device selected')->body('Select a connected device that supports adding or updating users.')->danger()->send();

                                    return;
                                }
                                Notification::make()
                                    ->title('Device updates queued')
                                    ->body("{$count} device update(s) queued.".($skipped ? " {$skipped} could not be queued." : ''))
                                    ->success()
                                    ->send();

                                return;
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
                        ->label(fn (): string => DeviceIntegration::usesEbio() ? 'Delete from Devices' : 'Remove from device')
                        ->modalDescription(fn (): string => DeviceIntegration::usesEbio()
                            ? 'Remove the selected employees from the selected eBio locations.'
                            : 'Remove the selected employees from the selected devices. Their employee records stay in Secumax.')
                        ->requiresConfirmation()
                        ->form([
                            Select::make('location')
                                ->label('Select Device(s)')
                                ->options(fn () => self::deviceOptions(DeviceIntegration::usesEbio() ? null : 'delete_user'))
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
                                $devices = $devices->filter(fn (Device $device) => $commandService->available($device, 'delete_user'));
                                $count = 0;
                                $skipped = 0;
                                foreach ($devices as $device) {
                                    foreach ($records as $record) {
                                        try {
                                            $commandService->request($device, 'delete_user', [
                                                'pin' => (string) $record->pin,
                                            ]);
                                            $count++;
                                        } catch (\Throwable $e) {
                                            report($e);
                                            $skipped++;
                                        }
                                    }
                                }
                                if ($count === 0) {
                                    Notification::make()->title('No compatible device selected')->body('Select a connected device that supports removing users.')->danger()->send();

                                    return;
                                }
                                Notification::make()
                                    ->title('Device removals queued')
                                    ->body("{$count} device removal(s) queued.".($skipped ? " {$skipped} could not be queued." : ''))
                                    ->success()
                                    ->send();

                                return;
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

    private static function deviceOptions(?string $operation = null): array
    {
        $commands = app(\App\Services\Devices\DeviceCommandService::class);

        return Device::query()->get()
            ->filter(fn (Device $device): bool => $operation === null || $commands->available($device, $operation))
            ->mapWithKeys(function (Device $device): array {
                $label = $device->name ?: $device->serial_number;
                $location = $device->options['location'] ?? null;

                return [$device->id => $location ? "{$label} ({$location})" : $label];
            })->all();
    }

    /** @return array<string, array<string, mixed>> */
    private static function storedFingerprintTemplates(User $record): array
    {
        $templates = [];
        foreach ((array) $record->fingerprints as $key => $template) {
            if (! is_array($template) || blank($template['tmp'] ?? null)) {
                continue;
            }
            $slot = (string) (is_numeric($key) ? $key : ($template['fid'] ?? $template['finger_id'] ?? $key));
            $templates[$slot] = $template;
        }

        return $templates;
    }

    /** @return array<string, array<string, mixed>> */
    private static function storedFaceTemplates(User $record): array
    {
        $templates = [];
        foreach ((array) $record->face_templates as $key => $template) {
            if (! is_array($template) || blank($template['tmp'] ?? null)) {
                continue;
            }
            $slot = (string) (is_numeric($key) ? $key : ($template['fid'] ?? $key));
            $templates[$slot] = $template;
        }

        return $templates;
    }

    /** @return list<string> */
    private static function storedFingerprintLabels(User $record): array
    {
        return array_map(fn (string $slot): string => self::fingerName($slot), array_keys(self::storedFingerprintTemplates($record)));
    }

    private static function fingerName(string $slot): string
    {
        $names = [
            0 => 'Left Pinky', 1 => 'Left Ring', 2 => 'Left Middle', 3 => 'Left Index', 4 => 'Left Thumb',
            5 => 'Right Thumb', 6 => 'Right Index', 7 => 'Right Middle', 8 => 'Right Ring', 9 => 'Right Pinky',
        ];

        return $names[(int) $slot] ?? "Finger {$slot}";
    }

    private static function biometricFetchDeviceOptions(): array
    {
        $commands = app(\App\Services\Devices\DeviceCommandService::class);

        return Device::query()->get()
            ->filter(fn (Device $device): bool => $commands->available($device, 'query_biodata'))
            ->mapWithKeys(fn (Device $device): array => [$device->id => $device->name ?: $device->serial_number])
            ->all();
    }

    private static function latestBiometricTransfer(User $record): string
    {
        $command = DeviceCommand::query()
            ->whereIn('command_type', ['update_biodata', 'update_biophoto'])
            ->latest('created_at')
            ->get()
            ->first(fn (DeviceCommand $command): bool => str_contains((string) $command->command_content, 'Employee: '.$record->pin.')'));
        if (! $command) {
            return 'No transfer requested yet';
        }
        $status = match ($command->status) {
            'acknowledged' => 'Completed',
            'failed' => 'Could not complete',
            'expired' => 'Device did not respond',
            'sent' => 'Waiting for device',
            default => 'Queued',
        };

        return trim(($command->device?->name ?: $command->device?->serial_number ?: 'Device').' — '.$status);
    }
}
