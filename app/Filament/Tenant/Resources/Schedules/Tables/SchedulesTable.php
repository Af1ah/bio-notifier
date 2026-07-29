<?php

namespace App\Filament\Tenant\Resources\Schedules\Tables;

use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Tables\Table;

class SchedulesTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                \Filament\Tables\Columns\TextColumn::make('name')
                    ->searchable()
                    ->sortable(),
                \Filament\Tables\Columns\TextColumn::make('target_type')
                    ->label('Target')
                    ->formatStateUsing(function ($record) {
                        if (! $record->target_type) return 'Organization (All)';
                        return class_basename($record->target_type) . ': ' . ($record->target ? $record->target->name : 'Unknown');
                    }),
                \Filament\Tables\Columns\TextColumn::make('users_count')
                    ->label('Users')
                    ->state(fn ($record) => $record->users_count),
                \Filament\Tables\Columns\TextColumn::make('valid_from')
                    ->date()
                    ->sortable(),
                \Filament\Tables\Columns\TextColumn::make('valid_to')
                    ->date()
                    ->sortable(),
                \Filament\Tables\Columns\IconColumn::make('status')
                    ->boolean(),
            ])
            ->filters([
                //
            ])
            ->recordActions([
                EditAction::make(),
            ])
            ->toolbarActions([
                BulkActionGroup::make([
                    DeleteBulkAction::make(),
                ]),
            ]);
    }
}
