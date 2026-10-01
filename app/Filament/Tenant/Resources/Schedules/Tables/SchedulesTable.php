<?php

namespace App\Filament\Tenant\Resources\Schedules\Tables;

use App\Services\Attendance\ShiftAssignmentService;
use Filament\Actions\Action;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\DatePicker;
use Filament\Notifications\Notification;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

class SchedulesTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('name')
                    ->searchable()
                    ->sortable(),
                TextColumn::make('latestRule.start_time')->label('Starts')->time('H:i'),
                TextColumn::make('latestRule.end_time')->label('Ends')->time('H:i'),
                TextColumn::make('valid_from')
                    ->date()
                    ->sortable()
                    ->visibleFrom('md'),
                TextColumn::make('valid_to')
                    ->date()
                    ->sortable()
                    ->visibleFrom('md'),
                IconColumn::make('status')
                    ->boolean(),
            ])
            ->filters([
                //
            ])
            ->recordActions([
                Action::make('makeDefault')->label('Make default')->icon('heroicon-o-star')->schema([
                    DatePicker::make('effective_from')->required()->default(today()),
                    DatePicker::make('effective_to')->rule('after_or_equal:effective_from'),
                ])->action(function ($record, array $data) {
                    app(ShiftAssignmentService::class)->setDefault($record, $data['effective_from'], $data['effective_to'] ?? null);
                    Notification::make()->title('Default shift updated')->success()->send();
                }),
                EditAction::make(),
            ])
            ->toolbarActions([
                BulkActionGroup::make([
                    DeleteBulkAction::make(),
                ]),
            ]);
    }
}
