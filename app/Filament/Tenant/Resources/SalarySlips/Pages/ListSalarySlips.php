<?php

namespace App\Filament\Tenant\Resources\SalarySlips\Pages;

use App\Filament\Tenant\Resources\SalarySlips\SalarySlipResource;
use App\Models\User;
use App\Models\Branch;
use App\Models\Department;
use App\Models\TaskGroup;
use App\Services\Payroll\PayrollEmployeeScope;
use App\Services\Payroll\SalarySlipExportService;
use App\Services\Payroll\SalarySlipService;
use Carbon\Carbon;
use Filament\Actions\Action;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ListRecords;
use Illuminate\Validation\ValidationException;

class ListSalarySlips extends ListRecords
{
    protected static string $resource = SalarySlipResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Action::make('generate')
                ->label('Generate salary slips')
                ->icon('heroicon-o-calculator')
                ->visible(fn (): bool => (int) auth()->user()?->privilege === 14)
                ->modalDescription('Before generating, enable payroll and complete salary details for employees, calculate attendance for the selected month, and resolve pending attendance approvals.')
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
                ->schema([
                    Select::make('month')->label('Payroll month')
                        ->options(collect(range(1, 12))->mapWithKeys(fn (int $month): array => [$month => Carbon::create(2000, $month, 1)->format('F')])->all())
                        ->default(now()->month)->required(),
                    TextInput::make('year')->label('Year')->integer()->minValue(1)->maxValue(9999)->default(now()->year)->required(),
                    Select::make('branch_ids')->label('Branches')->options(fn () => Branch::orderBy('name')->pluck('name', 'id'))->multiple()->searchable()->helperText('Blank means all branches.'),
                    Select::make('department_ids')->label('Departments')->options(fn () => Department::orderBy('name')->pluck('name', 'id'))->multiple()->searchable()->helperText('Blank means all departments.'),
                    Select::make('task_group_ids')->label('Task groups')->options(fn () => TaskGroup::orderBy('name')->pluck('name', 'id'))->multiple()->searchable()->helperText('Blank means all task groups.'),
                    Select::make('user_ids')->label('Employees')->options(fn () => User::employees()->whereHas('payrollProfile', fn ($query) => $query->where('payroll_enabled', true))->orderBy('name')->get()->mapWithKeys(fn (User $user): array => [$user->id => $user->name.' (PIN '.$user->pin.')'])->all())
                        ->multiple()->searchable()->helperText('Blank means all matching employees. Selected filters are combined.'),
                ])
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

                    $notification = Notification::make()
                        ->title("{$generated} salary slip(s) generated")
                        ->body($errors ? count($errors).' employee(s) skipped. '.$errors[0] : 'Draft slips are ready for review.');
                    $errors ? $notification->warning()->persistent()->send() : $notification->success()->send();
                }),
        ];
    }

    protected function authorizePayrollManagement(): void
    {
        abort_unless((int) auth()->user()?->privilege === 14, 403);
    }

    public function downloadFilteredPayslips()
    {
        $this->authorizePayrollManagement();

        return app(SalarySlipExportService::class)->pdf($this->getFilteredSortedTableQuery(), tenant()?->name ?? 'Organisation');
    }

    public function downloadFilteredPayroll()
    {
        $this->authorizePayrollManagement();

        return app(SalarySlipExportService::class)->csv($this->getFilteredSortedTableQuery());
    }
}
