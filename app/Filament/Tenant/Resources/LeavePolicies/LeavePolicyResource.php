<?php

namespace App\Filament\Tenant\Resources\LeavePolicies;

use App\Filament\Tenant\LeaveActionErrors;
use App\Filament\Tenant\PayrollSelectionFields;
use App\Models\LeavePolicy;
use App\Models\LeaveType;
use App\Models\User;
use App\Services\Leave\LeavePolicyService;
use App\Services\Leave\LeaveSettingsService;
use App\Services\Payroll\PayrollEmployeeScope;
use Carbon\Carbon;
use Filament\Actions\Action;
use Filament\Actions\EditAction;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Section;
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

        return $schema->components([
            Section::make('Policy and effective date')->description('Each company controls its own rules. Starter values are examples, not statutory entitlements. Saving creates a retained revision; issued credits keep their original rules and expiry.')->schema([
                TextInput::make('name')->required()->maxLength(120),
                Select::make('leave_type_id')->label('Leave type')->options(fn () => LeaveType::where('active', true)->pluck('name', 'id'))->required()->searchable(),
                Toggle::make('tenant_default')->label('Default for employees without an individual assignment')->default(true),
                DatePicker::make('effective_from')->required()->default(fn () => today(LeaveSettingsService::timezone())),
                Textarea::make('reason')->label('Reason for this revision')->required()->maxLength(1000),
                $toggle('enabled', 'Enable policy'),
            ])->columns(2),
            Section::make('Entitlement')->schema([
                Select::make('rules.mode')->label('Entitlement mode')->options(['monthly' => 'Monthly', 'annual' => 'Annual', 'compensatory' => 'Earned compensatory leave only'])->default('monthly')->required(),
                $days('grant_half_units', 'Leave granted per period', 732),
                Select::make('rules.grant_timing')->label('Grant timing')->options(['start' => 'Start of eligible period', 'end' => 'End of eligible period'])->default('start')->required(),
                $toggle('prorate_join_exit', 'Prorate join/exit using actual calendar days; round down to half-days'),
                $toggle('allow_unpaid_excess', 'Allow excess leave as unpaid'),
                $days('balance_cap_half_units', 'Maximum available entitlement balance'),
            ])->columns(2),
            Section::make('Carry-forward and expiry')->description('Credits retain their original expiry across rollovers. Fixed cutoff overrides relative expiry. Compensatory credits have their own expiry.')->schema([
                $toggle('carry_enabled', 'Carry unused entitlement forward'),
                $toggle('carry_roll_again', 'Allow further rollovers within original expiry'),
                $days('carry_cap_half_units', 'Maximum carried leave', 732),
                $number('expiry_months', 'Extra months after grant period (0 = period end)', 24),
                TextInput::make('rules.fixed_expiry_month')->label('Optional cutoff month (1–12)')->numeric()->integer()->minValue(1)->maxValue(12),
                TextInput::make('rules.fixed_expiry_day')->label('Optional cutoff day (1–31; shorter months use month end)')->numeric()->integer()->minValue(1)->maxValue(31),
            ])->columns(2),
            Section::make('Holiday and rostered off-day work')->description('Credit uses approved net minutes across the whole work date. HR chooses the reward after attendance review. Weekends follow the employee roster.')->schema([
                $toggle('comp_enabled', 'Enable compensatory rewards'), $toggle('comp_holiday', 'Eligible on holidays'), $toggle('comp_off', 'Eligible on rostered off days'),
                $number('comp_half_minutes', 'Minimum minutes for half-day reward', 1440), $number('comp_full_minutes', 'Minimum minutes for full-day reward', 1440),
                $number('comp_expiry_days', 'Credit expiry in days after availability', 730),
                $toggle('comp_same_day', 'Credit available on work date (leave/work overlap is still checked)'),
                Select::make('rules.comp_reward')->label('Allowed reward')->options(['choice' => 'HR chooses leave or payment', 'leave' => 'Leave only', 'payment' => 'Payment only', 'both' => 'Both leave and payment'])->default('choice')->required(),
                $toggle('comp_allow_overtime', 'Allow additional approved overtime with reward'),
                TextInput::make('rules.comp_payment_currency')->label('Payment currency')->default('INR')->required()->length(3)->dehydrateStateUsing(fn ($state) => strtoupper(trim($state))),
                TextInput::make('rules.comp_payment_full_minor')->label('Full-day reward payment')->helperText('Employee payroll currency must match the policy payment currency.')->numeric()->step(0.01)->minValue(0)->maxValue(1000000)->required()->default(0)->formatStateUsing(fn ($state) => number_format(((int) $state) / 100, 2, '.', ''))->dehydrateStateUsing(fn ($state) => (int) round(((float) $state) * 100)),
            ])->columns(2),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table->columns([
            TextColumn::make('name')->searchable(), TextColumn::make('leaveType.name')->label('Leave type'),
            TextColumn::make('tenant_default')->label('Scope')->formatStateUsing(fn ($state) => $state ? 'Tenant default' : 'Assigned employees'),
            TextColumn::make('revision')->state(fn (LeavePolicy $record) => $record->revisions()->orderByDesc('effective_from')->first()?->effective_from->toDateString())->label('Latest revision starts'),
        ])->recordActions([
            EditAction::make()->label('New revision'),
            Action::make('assign')->label('Assign employees')->schema([
                ...PayrollSelectionFields::make(),
                DatePicker::make('starts_on')->label('Starts on (month boundary)')->required()->default(fn () => today(LeaveSettingsService::timezone())->addMonthNoOverflow()->startOfMonth()), DatePicker::make('ends_on'),
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
            Action::make('history')->label('History')->modalHeading('Retained policy revisions')->modalContent(fn (LeavePolicy $record) => view('filament.tenant.pages.leave-history', ['rows' => $record->revisions()->orderByDesc('effective_from')->get()->toArray()]))->modalSubmitAction(false),
        ]);
    }

    public static function getPages(): array
    {
        return ['index' => Pages\ListLeavePolicies::route('/'), 'create' => Pages\CreateLeavePolicy::route('/create'), 'edit' => Pages\EditLeavePolicy::route('/{record}/edit')];
    }
}
