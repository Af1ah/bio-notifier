<?php

namespace App\Filament\Tenant\Resources\LeaveTypes\Tables;

use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Actions\ViewAction;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;

class LeaveTypesTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('name')->searchable()->sortable(),
                TextColumn::make('code')->badge()->searchable(),
                TextColumn::make('annual_allowance_half_units')
                    ->label('Paid allowance')
                    ->formatStateUsing(fn ($state): string => number_format(((int) $state) / 2, 1).' days')
                    ->sortable(),
                IconColumn::make('is_paid')->label('Paid')->boolean(),
                TextColumn::make('deduction_method')
                    ->label('Deduction')
                    ->badge()
                    ->formatStateUsing(fn ($state): string => match ($state) {
                        'salary_day_rate' => 'Salary day rate',
                        'basic_salary_31_day_rate' => 'Basic ÷ month days (February: 30)',
                        'fixed_amount' => 'Fixed amount',
                        default => 'None',
                    }),
                IconColumn::make('active')->boolean(),
            ])
            ->filters([
                TernaryFilter::make('active')->default(true),
            ])
            ->recordActions([
                ViewAction::make(),
                EditAction::make(),
            ])
            ->toolbarActions([
                BulkActionGroup::make([
                    DeleteBulkAction::make(),
                ]),
            ]);
    }
}
