<?php

namespace App\Filament\Tenant;

use App\Models\Branch;
use App\Models\Department;
use App\Models\TaskGroup;
use App\Models\User;
use App\Services\Payroll\PayrollEmployeeScope;
use Filament\Forms\Components\Select;
use Filament\Schemas\Components\Utilities\Get;

class PayrollSelectionFields
{
    public static function make(bool $enabledOnly = false, bool $respectTableFilters = false): array
    {
        return [
            Select::make('branch_ids')->label('Branches')->multiple()->searchable()->live()->options(fn ($livewire) => Branch::when($respectTableFilters && ($livewire->tableFilters['branch_ids']['values'] ?? []), fn ($q) => $q->whereIn('id', $livewire->tableFilters['branch_ids']['values']))->orderBy('name')->pluck('name', 'id'))->helperText('Blank means all matching branches.'),
            Select::make('department_ids')->label('Departments')->multiple()->searchable()->live()->options(fn (Get $get, $livewire) => Department::when($respectTableFilters && ($livewire->tableFilters['branch_ids']['values'] ?? []), fn ($q) => $q->whereIn('branch_id', $livewire->tableFilters['branch_ids']['values']))->when($get('branch_ids'), fn ($q, $ids) => $q->whereIn('branch_id', $ids))->orderBy('name')->pluck('name', 'id')),
            Select::make('task_group_ids')->label('Task groups')->multiple()->searchable()->live()->options(fn () => TaskGroup::orderBy('name')->pluck('name', 'id')),
            Select::make('user_ids')->label('Employees')->multiple()->searchable()->options(fn (Get $get, $livewire) => PayrollEmployeeScope::apply(User::query(), ['branch_ids' => $get('branch_ids') ?? [], 'department_ids' => $get('department_ids') ?? [], 'task_group_ids' => $get('task_group_ids') ?? []])->when($respectTableFilters, fn ($q) => PayrollEmployeeScope::apply($q, ['branch_ids' => $livewire->tableFilters['branch_ids']['values'] ?? [], 'department_ids' => $livewire->tableFilters['department_ids']['values'] ?? [], 'task_group_ids' => $livewire->tableFilters['task_group_ids']['values'] ?? []]))->when($enabledOnly, fn ($q) => $q->whereHas('payrollProfile', fn ($p) => $p->where('payroll_enabled', true)))->orderBy('name')->get()->mapWithKeys(fn ($u) => [$u->id => $u->name.' (PIN '.$u->pin.')']))->helperText('Filters apply first. Blank selects all matching employees; specific employees narrow that result.'),
        ];
    }
}
