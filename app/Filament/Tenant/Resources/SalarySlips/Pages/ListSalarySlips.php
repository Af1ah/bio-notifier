<?php

namespace App\Filament\Tenant\Resources\SalarySlips\Pages;

use App\Filament\Tenant\PayrollSelectionFields;
use App\Filament\Tenant\Resources\SalarySlips\SalarySlipResource;
use App\Models\SalarySlip;
use App\Models\User;
use App\Services\Payroll\ManualPayrollReviewService;
use App\Services\Payroll\PayrollEmployeeScope;
use App\Services\Payroll\SalarySlipExportService;
use App\Services\Payroll\SalarySlipService;
use Carbon\Carbon;
use Filament\Actions\Action;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Hidden;
use Filament\Forms\Components\Placeholder;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ListRecords;
use Filament\Schemas\Components\Group;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Schemas\Schema;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

class ListSalarySlips extends ListRecords
{
    protected static string $resource = SalarySlipResource::class;

    public function updatedTableFilters(): void
    {
        parent::updatedTableFilters();

        $this->tableGrouping = match ($this->tableFilters['display_group']['value'] ?? 'none') {
            'branch' => 'user.branch.name:asc',
            'department' => 'user.department.name:asc',
            default => null,
        };
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('reviewManualPeriod')->label('Review manual payroll period')->icon('heroicon-o-clipboard-document-check')
                ->visible(fn () => auth()->user()?->privilege === 14)
                ->modalDescription('Review attendance, approvals, leave and employment dates for one manual or unassigned payroll period. Evidence changes invalidate this review.')
                ->schema([
                    Select::make('user_id')->label('Employee')->options(fn () => User::employees()->whereHas('payrollProfile', fn ($q) => $q->where('payroll_enabled', true))->pluck('name', 'id'))->searchable()->required()->live()->afterStateUpdated(function ($state, Get $get, Set $set) {
                        $user = User::employees()->find($state);
                        $set('evidence_signature', $user && $get('period') ? app(ManualPayrollReviewService::class)->signature($user, Carbon::parse($get('period'))) : '');
                    }),
                    DatePicker::make('period')->label('Month (choose any date in that month)')->default(today()->startOfMonth())->required()->live()->afterStateUpdated(function ($state, Get $get, Set $set) {
                        $user = User::employees()->find($get('user_id'));
                        $set('evidence_signature', $user && $state ? app(ManualPayrollReviewService::class)->signature($user, Carbon::parse($state)) : '');
                    }),
                    Placeholder::make('evidence')->content(function (Get $get) {
                        $user = User::employees()->find($get('user_id'));
                        if (! $user || ! $get('period')) {
                            return 'Select an employee and month.';
                        }
                        $evidence = app(ManualPayrollReviewService::class)->evidence($user, Carbon::parse($get('period')));

                        $minutes = (int) collect($evidence['days'])->sum('approved_minutes');
                        return 'Eligible: '.$evidence['eligible_start'].'–'.$evidence['eligible_end'].' · Recorded days: '.count($evidence['days']).' · Work time: '.intdiv($minutes, 60).'h '.($minutes % 60).'m';
                    }),
                    Hidden::make('evidence_signature')->default('')->dehydrated(),
                    Toggle::make('confirmed')->label('I reviewed attendance, leave, salary and employment dates')->rule('accepted')->required(),
                    Textarea::make('reason')->required()->maxLength(2000),
                ])->action(function (array $data, Schema $schema) {
                    $this->authorizePayrollManagement();
                    $user = User::employees()->findOrFail($data['user_id']);
                    $period = Carbon::parse($data['period']);
                    $review = app(ManualPayrollReviewService::class);
                    try {
                        $review->approve($user, auth()->user(), $period, $data['reason'], $data['evidence_signature']);
                    } catch (ValidationException $exception) {
                        $message = collect($exception->errors())->flatten()->implode(' ');
                        Notification::make()->title('Payroll review not saved')->body($message)->danger()->send();
                        throw ValidationException::withMessages([$schema->getStatePath().'.period' => $message]);
                    }
                    Notification::make()->title('Manual payroll period reviewed')->success()->send();
                }),
            Action::make('generate')
                ->label('Generate salary slips')
                ->icon('heroicon-o-calculator')
                ->visible(fn (): bool => (int) auth()->user()?->privilege === 14)
                ->modalWidth('lg')
                ->modalDescription('Choose the month and employees. Payroll-disabled employees are excluded. Resolve any attendance issues before generating drafts.')
                ->modalSubmitActionLabel('Check and generate')
                ->beforeFormFilled(function (Action $action): void {
                    $this->authorizePayrollManagement();
                    $hasEnabledPayroll = User::employees()
                        ->whereHas('payrollProfile', fn ($query) => $query->where('payroll_enabled', true))
                        ->exists();

                    if ($hasEnabledPayroll) {
                        return;
                    }

                    Notification::make()
                        ->title('Payroll setup required')
                        ->body('Add salary details and enable payroll for at least one employee before generating salary slips.')
                        ->warning()
                        ->persistent()
                        ->send();

                    $action->halt();
                })
                ->schema(fn (): array => $this->generationSchema())
                ->action(function (array $data): void {
                    $this->authorizePayrollManagement();
                    $period = Carbon::create((int) $data['year'], (int) $data['month'], 1)->startOfMonth();
                    $users = PayrollEmployeeScope::apply(User::query(), $data)->whereHas('payrollProfile', fn ($query) => $query->where('payroll_enabled', true))->with('payrollProfile')->orderBy('id')->get();
                    if ($users->isEmpty()) {
                        Notification::make()->title('No matching employees')->body('No payroll-enabled employees match the selected filters.')->warning()->send();

                        return;
                    }
                    $service = app(SalarySlipService::class);
                    $generated = 0;
                    $errors = [];
                    foreach ($users as $user) {
                        $readinessIssues = $service->readinessIssues($user, $period);
                        if ($readinessIssues !== []) {
                            $errors[] = "{$user->name}: {$readinessIssues[0]}";

                            continue;
                        }

                        try {
                            $service->generate($user, $period);
                            $generated++;
                        } catch (ValidationException $exception) {
                            $errors[] = "{$user->name}: ".collect($exception->errors())->flatten()->first();
                        }
                    }

                    if ($generated === 0) {
                        Notification::make()
                            ->title('No salary slips generated')
                            ->body('Required before proceeding: '.($errors[0] ?? 'complete employee payroll and attendance data for the selected month.'))
                            ->danger()
                            ->persistent()
                            ->send();

                        return;
                    }

                    // Show the period just prepared so the result is immediately visible,
                    // even when the table was filtered to a different month.
                    $this->tableFilters['payroll_month'] = ['value' => $period->format('Y-m')];

                    $notification = Notification::make()
                        ->title("{$generated} salary slip(s) ready for {$period->format('F Y')}")
                        ->body($errors ? count($errors).' employee(s) skipped. '.$errors[0] : 'Draft slips are ready for review.');
                    $errors ? $notification->warning()->persistent()->send() : $notification->success()->send();
                }),
        ];
    }

    protected function authorizePayrollManagement(): void
    {
        abort_unless((int) auth()->user()?->privilege === 14, 403);
    }

    protected function generationSchema(): array
    {
        $selection = PayrollSelectionFields::make(true);

        return [
            Group::make([
                Select::make('month')->label('Payroll month')
                    ->options(collect(range(1, 12))->mapWithKeys(fn (int $month): array => [$month => Carbon::create(2000, $month, 1)->format('F')])->all())
                    ->default(now()->month)->required(),
                TextInput::make('year')->label('Year')->integer()->minValue(1)->maxValue(9999)->default(now()->year)->required(),
                $selection[3]->label('Employees')->columnSpanFull()->helperText('Leave blank to generate for all payroll-enabled employees.'),
                Section::make('Narrow the employee list')
                    ->description('Use these only when you need a branch, department, or task group subset.')
                    ->schema([$selection[0], $selection[1], $selection[2]])
                    ->columns(2)
                    ->collapsed()
                    ->columnSpanFull(),
            ])->columns(2),
        ];
    }

    public function exportQuery(array $data): Builder
    {
        $this->authorizePayrollManagement();
        Validator::make($data, ['period' => 'required|date_format:Y-m', 'scope' => 'required|in:filtered,all_matching', 'branch_ids' => 'array', 'department_ids' => 'array', 'task_group_ids' => 'array', 'user_ids' => 'array'])->validate();
        if ($data['scope'] === 'filtered') {
            $saved = $this->tableFilters;
            try {
                $this->tableFilters['payroll_month'] = ['value' => $data['period']];
                $query = $this->getFilteredSortedTableQuery();
            } finally {
                $this->tableFilters = $saved;
            }
        } else {
            $filters = $this->tableFilters ?? [];
            $global = [];
            foreach (['branch_ids', 'department_ids', 'task_group_ids'] as $key) {
                $global[$key] = $filters[$key]['values'] ?? [];
            }
            $query = SalarySlip::query()->whereHas('user', fn ($users) => PayrollEmployeeScope::apply($users, $global));
            $status = $filters['status']['value'] ?? 'unpaid';
            if ($status === 'unpaid') {
                $query->where('status', '!=', 'paid');
            } elseif ($status !== 'all') {
                $query->where('status', $status);
            }
        }

        return $query->whereHas('user', fn ($users) => PayrollEmployeeScope::apply($users, $data));
    }

    public function downloadFilteredPayslips(array $data = [])
    {
        return app(SalarySlipExportService::class)->pdf($this->exportQuery($data), tenant()?->name ?? 'Organisation', Carbon::createFromFormat('!Y-m', $data['period']));
    }

    public function downloadFilteredPayroll(array $data = [])
    {
        return app(SalarySlipExportService::class)->csv($this->exportQuery($data), Carbon::createFromFormat('!Y-m', $data['period']));
    }
}
