<?php

namespace App\Filament\Tenant\Resources\SalarySlips\Tables;

use App\Filament\Tenant\Actions\SalarySlipActions;
use App\Models\Branch;
use App\Models\Department;
use App\Models\TaskGroup;
use App\Models\SalarySlip;
use App\Services\Payroll\PayrollEmployeeScope;
use Filament\Actions\Action;
use Filament\Actions\ViewAction;
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
                SelectFilter::make('payroll_month')->label('Payroll month')->multiple()
                    ->options(fn (): array => SalarySlip::query()->select('period_start')->distinct()->orderByDesc('period_start')->get()->mapWithKeys(fn (SalarySlip $slip): array => [$slip->period_start->toDateString() => $slip->period_start->format('F Y')])->all())
                    ->query(fn ($query, array $data) => $query->when($data['values'] ?? [], fn ($query, $months) => $query->whereIn('period_start', $months))),
                SelectFilter::make('user_id')->label('Employee')->relationship('user', 'name')->searchable()->preload(),
                SelectFilter::make('branch_ids')->label('Branches')->multiple()->searchable()->options(fn () => Branch::orderBy('name')->pluck('name', 'id'))
                    ->query(fn ($query, array $data) => $query->when($data['values'] ?? [], fn ($query, $ids) => $query->whereHas('user', fn ($users) => PayrollEmployeeScope::apply($users, ['branch_ids' => $ids])))),
                SelectFilter::make('department_ids')->label('Departments')->multiple()->searchable()->options(fn () => Department::orderBy('name')->pluck('name', 'id'))
                    ->query(fn ($query, array $data) => $query->when($data['values'] ?? [], fn ($query, $ids) => $query->whereHas('user', fn ($users) => PayrollEmployeeScope::apply($users, ['department_ids' => $ids])))),
                SelectFilter::make('task_group_ids')->label('Task groups')->multiple()->searchable()->options(fn () => TaskGroup::orderBy('name')->pluck('name', 'id'))
                    ->query(fn ($query, array $data) => $query->when($data['values'] ?? [], fn ($query, $ids) => $query->whereHas('user', fn ($users) => PayrollEmployeeScope::apply($users, ['task_group_ids' => $ids])))),
                SelectFilter::make('status')->options([
                    'draft' => 'Draft',
                    'reviewed' => 'Reviewed',
                    'approved' => 'Approved',
                    'paid' => 'Paid',
                ]),
            ])
            ->headerActions([
                Action::make('downloadPayslips')->label('Download payslips')->icon('heroicon-o-document-arrow-down')->color('gray')
                    ->visible(fn (): bool => (int) auth()->user()?->privilege === 14)
                    ->action(fn ($livewire) => $livewire->downloadFilteredPayslips()),
                Action::make('downloadPayroll')->label('Download payroll CSV')->icon('heroicon-o-arrow-down-tray')->color('gray')
                    ->visible(fn (): bool => (int) auth()->user()?->privilege === 14)
                    ->action(fn ($livewire) => $livewire->downloadFilteredPayroll()),
            ])
            ->defaultSort('period_start', 'desc')
            ->recordActions([
                ViewAction::make(),
                ...SalarySlipActions::make(),
            ]);
    }
}
