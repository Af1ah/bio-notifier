<?php

namespace App\Filament\Tenant\Resources\EmployeeLeaves\Tables;

use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Actions\ViewAction;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;

class EmployeeLeavesTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('leave_date')->label('Date')->date('d M Y')->sortable(),
                TextColumn::make('user.name')->label('Employee')->description(fn ($record): string => 'PIN '.$record->user->pin)->searchable()->sortable(),
                TextColumn::make('leaveType.name')->label('Leave type')->badge()->searchable(),
                TextColumn::make('duration')->badge()->formatStateUsing(fn ($state): string => ucfirst($state).' day'),
                TextColumn::make('state')->badge()->color(fn ($state): string => $state === 'approved' ? 'success' : 'gray'),
                TextColumn::make('deduction_override_minor')
                    ->label('Deduction override')
                    ->formatStateUsing(fn ($state): string => $state === null ? 'Policy default' : 'INR '.number_format(((int) $state) / 100, 2))
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                SelectFilter::make('user_id')->label('Employee')->relationship('user', 'name')->searchable()->preload(),
                SelectFilter::make('leave_type_id')->label('Leave type')->relationship('leaveType', 'name')->preload(),
                SelectFilter::make('state')->options(['approved' => 'Approved', 'cancelled' => 'Cancelled']),
            ])
            ->defaultSort('leave_date', 'desc')
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
