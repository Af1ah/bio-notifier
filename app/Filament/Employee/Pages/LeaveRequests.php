<?php

namespace App\Filament\Employee\Pages;

use App\Models\EmployeeLeave;
use App\Models\LeaveType;
use App\Services\Attendance\AttendanceRateLimiter;
use App\Services\EmployeePortal\PortalAccess;
use App\Services\Leave\EmployeeLeaveService;
use App\Services\Leave\LeaveBalanceSummaryService;
use App\Services\Leave\LeavePolicyService;
use App\Services\Support\TenantTimezone;
use Carbon\Carbon;
use Filament\Actions\Action;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Notifications\Notification;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

class LeaveRequests extends PortalPage implements HasTable
{
    use InteractsWithTable;

    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-calendar';

    protected static ?int $navigationSort = 4;

    protected string $view = 'filament.employee.pages.leave-requests';

    public static function availableTypes(?string $date): array
    {
        $employee = PortalAccess::employee();
        $date = Validator::make(['date' => $date], ['date' => 'required|date_format:Y-m-d'])->passes()
            ? Carbon::parse($date) : today(TenantTimezone::get());
        $options = [];
        foreach (LeaveType::where('active', true)->orderBy('name')->get() as $type) {
            if (! $type->is_paid) {
                $options[$type->id] = $type->name.' · Unpaid';

                continue;
            }
            $policy = app(LeavePolicyService::class)->resolve($employee, $type->id, $date);
            if (! $policy || ! $policy->rules['enabled']) {
                continue;
            }
            $units = app(LeaveBalanceSummaryService::class)->get($employee, $type->id, $date)['available_half_units'];
            if ($units > 0) {
                $options[$type->id] = $type->name.' · '.($units / 2).' days available';
            }
        }

        return $options;
    }

    protected function getHeaderActions(): array
    {
        return [Action::make('requestLeave')->label('Request leave')->icon('heroicon-o-plus')->modalWidth('lg')
            ->modalDescription('Available balance is shown for your selected date.')
            ->schema([
                DatePicker::make('leave_date')->label('Date')->required()->default(fn () => today(TenantTimezone::get())->toDateString())->live(),
                Select::make('leave_type_id')->label('Leave type')->options(fn (Get $get) => self::availableTypes($get('leave_date')))->required(),
                Select::make('duration')->options(['full' => 'Full day', 'half' => 'Half day'])->default('full')->required(),
                Textarea::make('notes')->label('Reason')->rows(3)->maxLength(1000)->required(),
            ])->action(function (array $data, Schema $schema) {
                try {
                    $employee = PortalAccess::employee();
                    app(AttendanceRateLimiter::class)->ensure('employee_leave', 10);
                    app(EmployeeLeaveService::class)->save(null, ['user_id' => $employee->id, 'state' => 'pending'] + $data, $employee);
                } catch (ValidationException $exception) {
                    $errors = [];
                    foreach ($exception->errors() as $key => $messages) {
                        $errors[$schema->getStatePath().'.'.$key] = $messages;
                    }
                    throw ValidationException::withMessages($errors);
                }
                Notification::make()->title('Leave requested')->success()->send();
            })];
    }

    public function table(Table $table): Table
    {
        return $table->query(fn () => EmployeeLeave::where('user_id', PortalAccess::employee()->id)->with('leaveType'))
            ->columns([
                TextColumn::make('leave_date')->label('Date')->date('d M Y')->sortable(),
                TextColumn::make('leaveType.name')->label('Leave')->wrap(),
                TextColumn::make('state')->label('Status')->formatStateUsing(fn ($state) => ucfirst($state))
                    ->color(fn ($state) => match ($state) {
                        'approved' => 'success', 'rejected' => 'danger', 'pending' => 'warning', default => 'gray'
                    }),
                TextColumn::make('duration')->formatStateUsing(fn ($state) => ucfirst($state).' day')->visibleFrom('sm'),
                TextColumn::make('notes')->label('Reason')->wrap()->visibleFrom('md'),
            ])->filters([SelectFilter::make('state')->label('Status')->options(['pending' => 'Pending', 'approved' => 'Approved', 'rejected' => 'Rejected', 'cancelled' => 'Cancelled'])])
            ->defaultSort('leave_date', 'desc')->defaultKeySort()->paginated([10, 25, 50])->defaultPaginationPageOption(10)->enforcePaginationPageOptions()
            ->emptyStateHeading('No leave requests')->emptyStateDescription('Use Request leave to send a request.');
    }
}
