<?php

namespace App\Filament\Tenant\Resources\PayrollTemplates;

use App\Models\EmployeePayrollProfile;
use App\Models\PayrollTemplate;
use App\Models\User;
use App\Services\Payroll\PayrollTemplateService;
use Filament\Actions\Action;
use Filament\Actions\EditAction;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

class PayrollTemplateResource extends Resource
{
    protected static ?string $model = PayrollTemplate::class;

    protected static string|\UnitEnum|null $navigationGroup = 'Payroll';

    protected static ?string $navigationLabel = 'Payroll templates';

    protected static ?string $recordTitleAttribute = 'name';

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
        return static::canAccess() && ! EmployeePayrollProfile::where('payroll_template_id', $record->id)->exists();
    }

    public static function canDelete($record): bool
    {
        return false;
    }

    public static function form(Schema $schema): Schema
    {
        $money = fn ($field, $label) => TextInput::make('settings.'.$field)->label($label)->numeric()->minValue(0)->maxValue(1000000)->step(0.01)->required()->default(0)->formatStateUsing(fn ($state) => number_format((int) $state / 100, 2, '.', ''))->dehydrateStateUsing(fn ($state) => (int) round((float) $state * 100));

        return $schema->components([TextInput::make('name')->required()->maxLength(120), Select::make('branch_id')->relationship('branch', 'name')->searchable()->helperText('Blank applies to any branch.'), TextInput::make('role')->label('Employee designation')->maxLength(255)->helperText('Optional exact designation match.'), DatePicker::make('effective_from')->required()->default(today()), Select::make('settings.pay_basis')->label('Pay basis')->options(['monthly' => 'Monthly', 'daily' => 'Daily', 'hourly' => 'Hourly'])->default('monthly')->required(), TextInput::make('settings.currency')->label('Currency')->default('INR')->length(3)->required(), $money('basic_salary_minor', 'Monthly salary / daily or hourly rate'), $money('overtime_hourly_rate_minor', 'Employee overtime hourly rate'), $money('housing_allowance_minor', 'Monthly housing allowance'), $money('transport_allowance_minor', 'Monthly transport allowance'), $money('other_allowance_minor', 'Monthly other allowance'), $money('fixed_deduction_minor', 'Fixed monthly deduction')])->columns(2);
    }

    public static function table(Table $table): Table
    {
        return $table->columns([TextColumn::make('name')->searchable(), TextColumn::make('branch.name')->placeholder('Any branch'), TextColumn::make('role')->placeholder('Any designation'), TextColumn::make('settings.pay_basis')->label('Pay basis'), TextColumn::make('effective_from')->date()])->recordActions([EditAction::make(), Action::make('apply')->label('Preview and apply')->schema([
            Select::make('user_ids')->label('Employees')->options(fn (PayrollTemplate $record) => User::employees()->when($record->branch_id, fn ($q) => $q->where('branch_id', $record->branch_id))->when($record->role, fn ($q) => $q->where('group', $record->role))->pluck('name', 'id'))->multiple()->searchable()->required()->maxItems(1000),
            DatePicker::make('effective_from')->label('Apply from')->required()->minDate(fn () => today()->isStartOfMonth() ? today() : today()->addMonthNoOverflow()->startOfMonth())->default(fn () => today()->isStartOfMonth() ? today() : today()->addMonthNoOverflow()->startOfMonth())->helperText('Pay changes apply from the first day of a payroll month. Earlier, processed payslips are never changed.'),
            Select::make('template_overrides')->label('Keep these employee amounts')->multiple()->options(collect(\App\Services\Payroll\PayrollProfileHistoryService::MONEY)->mapWithKeys(fn ($v) => [$v => str($v)->replace('_minor', '')->replace('_', ' ')->title()])->all()),
        ])->action(function (PayrollTemplate $record, array $data) {
            $service = app(PayrollTemplateService::class); $preview = $service->preview($record, $data['user_ids'], auth()->user(), $data['effective_from'], $data['template_overrides'] ?? []);
            $result = $service->apply($record, $data['user_ids'], auth()->user(), $data['effective_from'], $data['template_overrides'] ?? [], $preview['signature']);
            Notification::make()->title($result['applied'].' employee pay profile(s) updated; '.$result['disabled'].' payroll-disabled employee(s) left unchanged.')->success()->send();
        })]);
    }

    public static function getPages(): array
    {
        return ['index' => Pages\ListPayrollTemplates::route('/'), 'create' => Pages\CreatePayrollTemplate::route('/create'), 'edit' => Pages\EditPayrollTemplate::route('/{record}/edit')];
    }
}
