<?php

namespace App\Filament\Tenant\Pages;

use App\Filament\Tenant\LeaveActionErrors;
use App\Models\LeaveCreditLot;
use App\Models\LeaveType;
use App\Models\User;
use App\Services\Leave\LeaveBalanceService;
use App\Services\Leave\LeaveSettingsService;
use Carbon\Carbon;
use Filament\Actions\Action;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Hidden;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;

class LeaveBalances extends Page implements HasTable
{
    use InteractsWithTable;

    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-banknotes';

    protected static \UnitEnum|string|null $navigationGroup = 'Payroll';

    protected static ?int $navigationSort = 3;

    protected string $view = 'filament.tenant.pages.leave-balances';

    public static function canAccess(): bool
    {
        return (int) auth()->user()?->privilege === 14;
    }

    public function selection(): array
    {
        if (! LeaveType::where('active', true)->where('is_paid', true)->exists()) {
            return ['leave_type_id' => 0, 'as_of' => today(LeaveSettingsService::timezone())->toDateString()];
        }

        return Validator::make($this->getTableFilterState('balance') ?? [], ['leave_type_id' => 'required|integer|exists:leave_types,id', 'as_of' => 'required|date_format:Y-m-d'])->validate();
    }

    public function employeeBalance(User $user): array
    {
        $v = $this->selection();

        return app(LeaveBalanceService::class)->balance($user, $v['leave_type_id'], Carbon::parse($v['as_of']));
    }

    public function table(Table $table): Table
    {
        return $table->query(User::employees())->columns([
            TextColumn::make('name')->label('Employee')->searchable()->description(fn ($record) => 'PIN '.$record->pin),
            ...collect(['available' => 'Available', 'carried' => 'Carried', 'reserved' => 'Reserved', 'consumed' => 'Consumed', 'expiring' => 'Expiring soon'])
                ->map(fn ($label, $key) => TextColumn::make($key)->label($label.' days')->state(fn (User $record) => $this->employeeBalance($record)[$key.'_half_units'] / 2))->values()->all(),
        ])->filters([
            Filter::make('balance')->schema([
                Select::make('leave_type_id')->label('Leave type')->options(fn () => LeaveType::where('active', true)->where('is_paid', true)->pluck('name', 'id'))->default(fn () => LeaveType::where('active', true)->where('is_paid', true)->value('id'))->required(),
                DatePicker::make('as_of')->label('Balance date')->default(fn () => today(LeaveSettingsService::timezone())->toDateString())->required(),
            ]),
            SelectFilter::make('branch_id')->relationship('branch', 'name')->label('Branch'),
            SelectFilter::make('department_id')->relationship('department', 'name')->label('Department'),
        ])->recordActions([
            Action::make('history')->label('History')->modalContent(function (User $record) {
                $v = $this->selection();

                return view('filament.tenant.pages.leave-credit-history', ['lots' => LeaveCreditLot::with('entries')->where('user_id', $record->id)->where('leave_type_id', $v['leave_type_id'])->orderByDesc('id')->get()]);
            })->modalSubmitAction(false),
            Action::make('reduce')->label('Reduce reviewed balance')->schema([
                Select::make('lot_id')->label('Credit')->options(fn (User $record) => LeaveCreditLot::where('user_id', $record->id)->where('source_kind', '!=', 'compensatory')->orderByDesc('id')->get()->mapWithKeys(fn ($lot) => [$lot->id => $lot->leaveType->name.' — '.$lot->available_on->toDateString().' — expires '.$lot->expires_on->toDateString()]))->required()->searchable(),
                TextInput::make('days')->numeric()->step(0.5)->rules(['multiple_of:0.5'])->minValue(0.5)->maxValue(732)->required(), Textarea::make('reason')->required()->maxLength(1000), Hidden::make('request_key')->default(fn () => (string) Str::uuid()),
            ])->action(function (User $record, array $data, Schema $schema) {
                $lot = LeaveCreditLot::where('user_id', $record->id)->findOrFail($data['lot_id']);
                LeaveActionErrors::run($schema, fn () => app(LeaveBalanceService::class)->reduce($lot, (int) round($data['days'] * 2), $data['reason'], auth()->user(), $data['request_key']));
            }),
            Action::make('opening')->label('Add reviewed balance')->schema([
                Select::make('leave_type_id')->label('Leave type')->options(fn () => LeaveType::where('is_paid', true)->where('active', true)->pluck('name', 'id'))->required(),
                TextInput::make('half_units')->label('Leave days')->numeric()->step(0.5)->rules(['multiple_of:0.5'])->minValue(0.5)->maxValue(732)->required()->dehydrateStateUsing(fn ($state) => (int) round(((float) $state) * 2)),
                DatePicker::make('available_on')->required()->default(fn () => today(LeaveSettingsService::timezone())), DatePicker::make('expires_on')->required(),
                Textarea::make('reason')->label('Verified opening balance / adjustment reason')->required()->maxLength(1000), Hidden::make('request_key')->default(fn () => (string) Str::uuid()),
            ])->action(function (User $record, array $data, Schema $schema) {
                LeaveActionErrors::run($schema, fn () => app(LeaveBalanceService::class)->opening($record, LeaveType::findOrFail($data['leave_type_id']), (int) $data['half_units'], Carbon::parse($data['available_on']), Carbon::parse($data['expires_on']), $data['reason'], auth()->user(), $data['request_key']));
                Notification::make()->title('Reviewed balance added')->success()->send();
            }),
        ])->emptyStateHeading('No employees or leave types')->emptyStateDescription('Create leave types and review Leave Settings before using balances.');
    }
}
