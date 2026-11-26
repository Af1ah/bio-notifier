<?php

namespace App\Filament\Tenant\Resources\Departments\Schemas;

use App\Services\EmployeePortal\DepartmentReview;
use Filament\Forms\Components\TextInput;
use Illuminate\Database\Eloquent\Builder;
use Filament\Forms\Components\Select;
use Filament\Schemas\Schema;

class DepartmentForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([

                TextInput::make('name')
                    ->required(),
                Select::make('manager_id')
                    ->label(__('department-review.manager'))
                    ->relationship('manager', 'name', modifyQueryUsing: fn (Builder $query) => $query->employees()
                        ->where('is_enabled', true)->whereNotIn('privilege', [14, \App\Models\User::HR_PRIVILEGE])
                        ->whereNotNull('email')->where('email', '!=', '')
                        ->where(fn (Builder $query) => $query->where('portal_enabled', true)->whereNotNull('password')->where('password', '!=', '')
                            ->orWhere('portal_enabled', false)->whereNotNull('device_password')))
                    ->searchable(['name', 'email'])
                    ->visible(fn (): bool => DepartmentReview::canAssign()),
                Select::make('branches')
                    ->relationship('branches', 'name')
                    ->getOptionLabelFromRecordUsing(fn ($record) => $record->display_name)
                    ->multiple()
                    ->searchable()
                    ->preload(),
            ]);
    }
}
