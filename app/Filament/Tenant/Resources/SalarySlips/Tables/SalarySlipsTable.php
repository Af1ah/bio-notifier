<?php

namespace App\Filament\Tenant\Resources\SalarySlips\Tables;

use App\Filament\Tenant\Actions\SalarySlipActions;
use App\Filament\Tenant\PayrollSelectionFields;
use App\Models\Branch;
use App\Models\Department;
use App\Models\SalarySlip;
use App\Models\TaskGroup;
use App\Services\Payroll\PayrollEmployeeScope;
use Carbon\Carbon;
use Filament\Actions\Action;
use Filament\Actions\ViewAction;
use Filament\Forms\Components\Select;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;

class SalarySlipsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('period_start')->label('Month')->date('M Y')->sortable(),
                TextColumn::make('user.name')->label('Employee')->description(fn ($record): string => 'PIN '.$record->user->pin)->searchable()->sortable(),
                TextColumn::make('designation')->placeholder('—')->searchable()->toggleable(),
                TextColumn::make('user.branch.name')->label('Branch')->placeholder('—')->sortable()->toggleable(),
                TextColumn::make('user.department.name')->label('Department')->placeholder('—')->sortable()->toggleable(),
                TextColumn::make('user.taskGroups.name')->label('Task groups')->badge()->toggleable(),
                TextColumn::make('gross_pay_minor')->label('Gross pay')->formatStateUsing(fn ($state, $record): string => $record->currency.' '.number_format(((int) $state) / 100, 2))->sortable(),
                TextColumn::make('total_deduction_minor')->label('Total deduction')->formatStateUsing(fn ($state, $record): string => $record->currency.' '.number_format(((int) $state) / 100, 2))->sortable(),
                TextColumn::make('net_pay_minor')->label('Net pay')->weight('bold')->formatStateUsing(fn ($state, $record): string => $record->currency.' '.number_format(((int) $state) / 100, 2))->sortable(),
                TextColumn::make('status')->badge()->color(fn ($state): string => match ($state) {
                    'paid', 'approved' => 'success',
                    'reviewed' => 'warning',
                    default => 'gray',
                }),
            ])
            ->filters([
                SelectFilter::make('payroll_month')->label('Payroll month')->default(now()->format('Y-m'))
                    ->options(fn () => SalarySlip::orderByDesc('period_start')->get()->mapWithKeys(fn ($slip) => [$slip->period_start->format('Y-m') => $slip->period_start->format('F Y')])->all())
                    ->query(fn ($query, array $data) => $query->when($data['value'] ?? null, fn ($q, $month) => $q->whereDate('period_start', Carbon::createFromFormat('!Y-m', $month)))),
                SelectFilter::make('user_id')->label('Employee')->relationship('user', 'name')->searchable()->preload(),
                SelectFilter::make('branch_ids')->label('Branches')->multiple()->searchable()->options(fn () => Branch::orderBy('name')->pluck('name', 'id'))
                    ->query(fn ($query, array $data) => $query->when($data['values'] ?? [], fn ($query, $ids) => $query->whereHas('user', fn ($users) => PayrollEmployeeScope::apply($users, ['branch_ids' => $ids])))),
                SelectFilter::make('department_ids')->label('Departments')->multiple()->searchable()->options(fn () => Department::orderBy('name')->pluck('name', 'id'))
                    ->query(fn ($query, array $data) => $query->when($data['values'] ?? [], fn ($query, $ids) => $query->whereHas('user', fn ($users) => PayrollEmployeeScope::apply($users, ['department_ids' => $ids])))),
                SelectFilter::make('task_group_ids')->label('Task groups')->multiple()->searchable()->options(fn () => TaskGroup::orderBy('name')->pluck('name', 'id'))
                    ->query(fn ($query, array $data) => $query->when($data['values'] ?? [], fn ($query, $ids) => $query->whereHas('user', fn ($users) => PayrollEmployeeScope::apply($users, ['task_group_ids' => $ids])))),
                SelectFilter::make('status')->default('unpaid')->options(['unpaid' => 'Unpaid (hide paid)', 'all' => 'All statuses', 'draft' => 'Draft', 'reviewed' => 'Reviewed', 'approved' => 'Approved', 'paid' => 'Paid'])
                    ->query(fn ($query, array $data) => ($data['value'] ?? 'unpaid') === 'unpaid' ? $query->where('status', '!=', 'paid') : $query->when(($data['value'] ?? 'all') !== 'all', fn ($q) => $q->where('status', $data['value']))),
            ])
            ->headerActions([
                Action::make('downloadPayslips')->label('Download payslips')->icon('heroicon-o-document-arrow-down')->color('gray')
                    ->visible(fn (): bool => (int) auth()->user()?->privilege === 14)
                    ->schema(self::exportFields())->action(fn ($livewire, array $data) => $livewire->downloadFilteredPayslips($data)),
                Action::make('downloadPayroll')->label('Download payroll CSV')->icon('heroicon-o-arrow-down-tray')->color('gray')
                    ->visible(fn (): bool => (int) auth()->user()?->privilege === 14)
                    ->schema(self::exportFields())->action(fn ($livewire, array $data) => $livewire->downloadFilteredPayroll($data)),
            ])
            ->defaultSort('period_start', 'desc')
            ->recordActions([
                ViewAction::make(),
                ...SalarySlipActions::make(),
            ]);
    }

    private static function exportFields(): array
    {
        return [
            Select::make('period')->label('Export month')->required()->options(fn () => SalarySlip::orderByDesc('period_start')->get()->mapWithKeys(fn ($s) => [$s->period_start->format('Y-m') => $s->period_start->format('F Y')])->all()),
            Select::make('scope')->label('Employee scope')->options(['filtered' => 'Current filtered employees', 'all_matching' => 'All employees matching branch / department / group filters'])->default('filtered')->required(),
            ...PayrollSelectionFields::make(false, true),
        ];
    }
}
