<?php

namespace App\Filament\Tenant\Resources\LeavePolicies;

use App\Filament\Tenant\LeaveActionErrors;
use App\Filament\Tenant\PayrollSelectionFields;
use App\Filament\Tenant\Resources\LeaveTypes\Schemas\LeaveTypeForm;
use App\Models\LeavePolicy;
use App\Models\LeaveType;
use App\Models\User;
use App\Services\Leave\LeavePolicyRemovalService;
use App\Services\Leave\LeavePolicyService;
use App\Services\Leave\LeaveSettingsService;
use App\Services\Payroll\PayrollEmployeeScope;
use Carbon\Carbon;
use Filament\Actions\Action;
use Filament\Actions\ActionGroup;
use Filament\Actions\EditAction;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Hidden;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Support\Facades\DB;

class LeavePolicyResource extends Resource
{
    protected static ?string $model = LeavePolicy::class;

    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-adjustments-horizontal';

    protected static \UnitEnum|string|null $navigationGroup = 'Payroll';

    protected static ?string $navigationLabel = 'Leave settings';

    protected static ?int $navigationSort = 2;

    public static function canAccess(): bool
    {
        return (int) auth()->user()?->privilege === 14;
    }

    public static function canViewAny(): bool
    {
        return static::canAccess();
    }

    public static function canCreate(): bool
    {
        return static::canAccess();
    }

    public static function canEdit($record): bool
    {
        return static::canAccess();
    }

    public static function canView($record): bool
    {
        return static::canAccess();
    }

    public static function canDelete($record): bool
    {
        return false;
    }

    public static function form(Schema $schema): Schema
    {
        $d = LeavePolicyService::defaults();
        $number = fn ($key, $label, $max = 1464) => TextInput::make('rules.'.$key)->label($label)->numeric()->integer()->minValue(0)->maxValue($max)->required()->default($d[$key]);
        $days = fn ($key, $label, $max = 1464) => TextInput::make('rules.'.$key)->label($label)->numeric()->minValue(0)->maxValue($max / 2)->step(0.5)->rules(['multiple_of:0.5'])->suffix('days')->required()->default($d[$key])->formatStateUsing(fn ($state) => number_format(((int) $state) / 2, 1, '.', ''))->dehydrateStateUsing(fn ($state) => (int) round(((float) $state) * 2));
        $toggle = fn ($key, $label) => Toggle::make('rules.'.$key)->label($label)->default($d[$key]);

        $regular = fn (Get $get) => $get('rules.mode') !== 'compensatory';
        $carry = fn (Get $get) => $get('rules.mode') !== 'compensatory' && $get('rules.carry_enabled');
        $rewards = fn (Get $get) => $get('rules.mode') === 'compensatory' || $get('rules.comp_enabled');
        $payment = fn (Get $get) => ($get('rules.mode') === 'compensatory' || $get('rules.comp_enabled')) && $get('rules.comp_reward') !== 'leave';

        return $schema->columns(1)->components([
            Section::make('Policy')->description('Set the category, employee scope and start date. Changes create a revision; issued credits keep their recorded expiry.')->schema([
                TextInput::make('name')->required()->maxLength(120),
                Select::make('leave_type_id')->label('Leave category')->options(fn () => LeaveType::where('active', true)->where('is_paid', true)->pluck('name', 'id'))->required()->searchable()
                    ->createOptionForm(fn (Schema $schema) => LeaveTypeForm::configure($schema->model(LeaveType::class), paidOnly: true))
                    ->createOptionUsing(function (array $data) {
                        abort_unless((int) auth()->user()?->privilege === 14, 403);

                        return LeaveType::create(array_replace($data, ['is_paid' => true, 'annual_allowance_half_units' => 0]))->id;
                    })
                    ->helperText('Create a paid category here. Manage unpaid categories and deduction rules through Leave categories.'),
                Toggle::make('tenant_default')->label('Default for employees without an individual assignment')->default(true),
                DatePicker::make('rules.leave_period_from')->label('Leave period starts')->native(false)->helperText('Optional company leave year, such as 1 April 2026. Set both period dates.')->visible($regular),
                DatePicker::make('rules.leave_period_to')->label('Leave period ends')->native(false)->helperText('For example, 31 March 2027. New regular grants stop after this date; valid carried credits keep their own expiry.')->visible($regular),
                DatePicker::make('effective_from')->required()->default(fn () => today(LeaveSettingsService::timezone())),
                Textarea::make('reason')->label('Reason for this revision')->required()->maxLength(1000)->rows(3)->columnSpanFull(),
                $toggle('enabled', 'Enable policy'),
            ])->columns(['default' => 1, 'md' => 2])->columnSpanFull(),
            Section::make('Entitlement and unused leave')->description('Unused entitlement is carried automatically within its original expiry and carry limit.')->schema([
                Select::make('rules.mode')->label('Entitlement')->options(['monthly' => 'Monthly', 'annual' => 'Annual', 'compensatory' => 'Earned from holiday/off-day work'])->default('monthly')->required()->live(),
                $days('grant_half_units', 'Leave granted per period', 732)->visible($regular),
                Select::make('rules.grant_timing')->label('Grant timing')->options(['start' => 'Start of eligible period', 'end' => 'End of eligible period'])->default('start')->required()->visible($regular),
                $toggle('prorate_join_exit', 'Prorate join/exit; round down to half-days')->visible($regular),
                $toggle('allow_unpaid_excess', 'Allow excess leave as unpaid')->live()->helperText('Covered leave remains paid. Only the uncovered portion follows the unpaid-excess rule below.'),
                Select::make('rules.unpaid_excess_deduction_method')->label('Unpaid excess deduction')->options([
                    'salary_day_rate' => 'Employee salary day rate', 'basic_salary_31_day_rate' => 'Basic salary ÷ actual calendar days', 'fixed_amount' => 'Fixed amount per full day',
                ])->default('salary_day_rate')->required()->live()->visible(fn (Get $get) => $get('rules.allow_unpaid_excess')),
                TextInput::make('rules.unpaid_excess_deduction_minor')->label('Unpaid excess deduction per full day')->numeric()->step(0.01)->minValue(0.01)->required()->default(0)
                    ->formatStateUsing(fn ($state) => number_format(((int) $state) / 100, 2, '.', ''))
                    ->dehydrateStateUsing(fn ($state) => (int) round(((float) $state) * 100))
                    ->visible(fn (Get $get) => $get('rules.allow_unpaid_excess') && $get('rules.unpaid_excess_deduction_method') === 'fixed_amount'),
                $days('balance_cap_half_units', 'Maximum regular entitlement balance')->helperText('Limits new regular grants. Opening balances and work rewards are excluded.')->visible($regular),
                $toggle('carry_enabled', 'Keep unused leave after the grant period')->live()->visible($regular),
                $days('carry_cap_half_units', 'Maximum carried leave', 732)->visible($carry),
                Select::make('rules.expiry_method')->label('Unused leave expires')->options(['relative' => 'After additional months', 'fixed' => 'On an annual cutoff date', 'resets' => 'On recurring reset dates'])->default('relative')->required()->live()->visible($carry),
                $number('expiry_months', 'Additional months after the grant period', 24)->visible(fn (Get $get) => $carry($get) && $get('rules.expiry_method') === 'relative'),
                Repeater::make('rules.reset_dates')->label('Reset dates')->schema([DatePicker::make('date')->label('Reset at start of date')->required()->native(false)])->maxItems(12)->minItems(1)->defaultItems(0)->addActionLabel('Add reset date')->helperText('Month and day repeat every year. Old credit expires the previous day. A grant issued on the reset date starts the new cycle.')->visible(fn (Get $get) => $carry($get) && $get('rules.expiry_method') === 'resets')->columnSpanFull(),
                DatePicker::make('rules.annual_cutoff_date')->label('Annual expiry cutoff')->native(false)->displayFormat('d F Y')->required()
                    ->helperText('Choose a date, for example 31 March. Its month and day repeat every year; the selected year is not a one-time expiry. Each grant uses the first cutoff on or after its grant period ends.')
                    ->visible(fn (Get $get) => $carry($get) && $get('rules.expiry_method') === 'fixed'),
            ])->columns(['default' => 1, 'md' => 2])->columnSpanFull(),
            Section::make('Holiday and off-day work')->description('Half/full-day rewards inherit the effective daily shift thresholds and use approved net minutes across the work date.')->schema([
                $toggle('comp_enabled', 'Enable work rewards')->live()->visible($regular),
                $toggle('comp_holiday', 'Eligible on holidays')->visible($rewards),
                $toggle('comp_off', 'Eligible on rostered off days')->visible($rewards),
                Select::make('rules.comp_reward')->label('Reward')->options(['choice' => 'HR chooses leave or payment', 'leave' => 'Leave only', 'payment' => 'Payment only', 'both' => 'Leave and payment together'])->default('choice')->required()->live()->visible($rewards),
                Select::make('rules.comp_expiry_mode')->label('Earned leave expires')->options(['days' => 'After a number of days', 'work_month_end' => 'At the end of the month worked'])->default('days')->required()->live()->visible(fn (Get $get) => $rewards($get) && $get('rules.comp_reward') !== 'payment'),
                $number('comp_expiry_days', 'Credit expires after availability', 730)->suffix('days')->visible(fn (Get $get) => $rewards($get) && $get('rules.comp_reward') !== 'payment' && $get('rules.comp_expiry_mode') !== 'work_month_end'),
                $toggle('comp_same_day', 'Credit available on work date')->helperText('Work and leave overlap checks still apply.')->visible(fn (Get $get) => $rewards($get) && $get('rules.comp_reward') !== 'payment'),
                $toggle('comp_allow_overtime', 'Allow approved overtime alongside this reward')->visible($rewards),
                TextInput::make('rules.comp_payment_currency')->label('Payment currency')->default('INR')->required()->length(3)->dehydrateStateUsing(fn ($state) => strtoupper(trim($state)))->visible($payment),
                TextInput::make('rules.comp_payment_full_minor')->label('Full-day reward payment')->helperText('Half-day pays half. Payment needs a positive amount and matching employee payroll currency.')->numeric()->step(0.01)->minValue(0)->maxValue(1000000)->required()->default(0)->formatStateUsing(fn ($state) => number_format(((int) $state) / 100, 2, '.', ''))->dehydrateStateUsing(fn ($state) => (int) round(((float) $state) * 100))->visible($payment),
            ])->columns(['default' => 1, 'md' => 2])->columnSpanFull(),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table->columns([
            TextColumn::make('name')->searchable(), TextColumn::make('leaveType.name')->label('Leave type'),
            TextColumn::make('tenant_default')->label('Scope')->formatStateUsing(fn ($state) => $state ? 'Default' : 'Assigned employees'),
            TextColumn::make('revision')->state(fn (LeavePolicy $record) => $record->revisions()->orderByDesc('effective_from')->first()?->effective_from->toDateString())->label('Latest revision starts'),
        ])->recordActions([
            ActionGroup::make([
                EditAction::make()->label('New revision'),
                Action::make('assign')->label('Assign employees')->schema([
                    ...PayrollSelectionFields::make(),
                    DatePicker::make('starts_on')->label('Starts on')->helperText('Use month start for monthly policies, or the configured leave-period start for annual policies.')->required()->default(fn () => today(LeaveSettingsService::timezone())->addMonthNoOverflow()->startOfMonth()), DatePicker::make('ends_on'),
                ])->action(function (LeavePolicy $record, array $data, Schema $schema) {
                    $query = PayrollEmployeeScope::apply(User::employees(), $data);
                    LeaveActionErrors::run($schema, fn () => app(LeavePolicyService::class)->assign($record, $query->pluck('id')->all(), Carbon::parse($data['starts_on']), empty($data['ends_on']) ? null : Carbon::parse($data['ends_on']), auth()->user()), 'starts_on');
                    Notification::make()->title('Employees assigned')->success()->send();
                }),
                Action::make('endAssignment')->label('End assignment')->schema([
                    Select::make('assignment_id')->label('Employee assignment')->options(fn (LeavePolicy $record) => DB::table('employee_leave_policy_assignments')->join('users', 'users.id', '=', 'employee_leave_policy_assignments.user_id')->where('leave_policy_id', $record->id)->where(fn ($q) => $q->whereNull('ends_on')->orWhereDate('ends_on', '>=', today(LeaveSettingsService::timezone())))->select('employee_leave_policy_assignments.id', 'users.name', 'starts_on')->get()->mapWithKeys(fn ($a) => [$a->id => $a->name.' — from '.$a->starts_on]))->required()->searchable(),
                    DatePicker::make('ends_on')->required()->default(fn () => today(LeaveSettingsService::timezone())->endOfMonth()), Textarea::make('reason')->required()->maxLength(1000),
                ])->action(function (LeavePolicy $record, array $data, Schema $schema) {
                    abort_unless(DB::table('employee_leave_policy_assignments')->where('id', $data['assignment_id'])->where('leave_policy_id', $record->id)->exists(), 422);
                    LeaveActionErrors::run($schema, fn () => app(LeavePolicyService::class)->endAssignment((int) $data['assignment_id'], Carbon::parse($data['ends_on']), $data['reason'], auth()->user()));
                }),
                Action::make('removePolicy')->label('Remove policy')->color('danger')->icon('heroicon-o-trash')
                    ->visible(fn () => static::canAccess())
                    ->modalHeading('Remove leave policy')
                    ->modalDescription('Removes this policy and its unprocessed records. Reviewed, approved and paid payslips and their required evidence are preserved. This cannot be undone from this page.')
                    ->fillForm(function (LeavePolicy $record) {
                        $preview = app(LeavePolicyRemovalService::class)->preview($record, auth()->user());

                        return ['removal_summary' => $preview['summary'], 'expected_signature' => $preview['signature']];
                    })
                    ->schema([
                        Textarea::make('removal_summary')->label('Records affected')->disabled()->rows(10)->columnSpanFull(),
                        Hidden::make('expected_signature')->required(),
                        TextInput::make('confirmation')->label('Type the exact policy name')->required()->maxLength(120),
                        Textarea::make('reason')->label('Removal reason')->required()->maxLength(1000),
                    ])
                    ->modalSubmitActionLabel('Remove policy')
                    ->action(function (LeavePolicy $record, array $data, Schema $schema) {
                        LeaveActionErrors::run($schema, fn () => app(LeavePolicyRemovalService::class)->remove($record, auth()->user(), $data['confirmation'], $data['reason'], $data['expected_signature']), 'confirmation');
                        Notification::make()->title('Policy removed')->success()->send();
                    }),
                Action::make('history')->label('History')->modalHeading('Retained policy revisions')->modalContent(fn (LeavePolicy $record) => view('filament.tenant.pages.leave-history', ['rows' => $record->revisions()->orderByDesc('effective_from')->get()->toArray()]))->modalSubmitAction(false),
            ])->icon('heroicon-o-ellipsis-vertical')->label('Policy actions')->tooltip('Policy actions'),
        ]);
    }

    public static function getPages(): array
    {
        return ['index' => Pages\ListLeavePolicies::route('/'), 'create' => Pages\CreateLeavePolicy::route('/create'), 'edit' => Pages\EditLeavePolicy::route('/{record}/edit')];
    }
}
