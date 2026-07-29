<?php

namespace App\Filament\Master\Resources\AdmsPayloads\Tables;

use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Actions\ViewAction;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

class AdmsPayloadsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('serial_number')
                    ->searchable(),
                TextColumn::make('tenant_id')
                    ->searchable(),
                TextColumn::make('table_name')
                    ->searchable(),
                TextColumn::make('stamp')
                    ->searchable(),
                TextColumn::make('status')
                    ->badge()
                    ->color(fn (string $state): string => match ($state) {
                        'pending' => 'warning',
                        'failed' => 'danger',
                        default => 'gray',
                    }),
                TextColumn::make('error_message')
                    ->limit(50)
                    ->searchable(),
                TextColumn::make('created_at')
                    ->dateTime()
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('updated_at')
                    ->dateTime()
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                \Filament\Tables\Filters\SelectFilter::make('status')
                    ->options([
                        'pending' => 'Pending',
                        'failed' => 'Failed',
                    ])
                    ->default('failed'),
            ])
            ->recordActions([
                ViewAction::make(),
                \Filament\Actions\Action::make('retry')
                    ->label('Retry')
                    ->icon('heroicon-o-arrow-path')
                    ->color('warning')
                    ->requiresConfirmation()
                    ->action(function (\App\Models\AdmsPayload $record) {
                        $record->update(['status' => 'pending']);
                        \App\Jobs\ProcessAdmsPayload::dispatch($record);
                        \Filament\Notifications\Notification::make()
                            ->title('Payload Re-queued')
                            ->success()
                            ->send();
                    }),
            ])
            ->toolbarActions([
                BulkActionGroup::make([
                    DeleteBulkAction::make(),
                ]),
            ]);
    }
}
