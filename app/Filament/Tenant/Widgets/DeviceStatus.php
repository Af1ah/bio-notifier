<?php

namespace App\Filament\Tenant\Widgets;

use Filament\Actions\BulkActionGroup;
use Filament\Tables\Table;
use Filament\Widgets\TableWidget;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

class DeviceStatus extends TableWidget
{
    protected ?string $pollingInterval = "30s";

    protected int | string | array $columnSpan = 'full';
    protected static ?int $sort = 4;

    public function table(Table $table): Table
    {
        return $table
            ->query(\App\Models\Device::query())
            ->columns([
                \Filament\Tables\Columns\TextColumn::make('name')
                    ->searchable()
                    ->sortable(),
                \Filament\Tables\Columns\TextColumn::make('type')
                    ->searchable()
                    ->sortable(),
                \Filament\Tables\Columns\TextColumn::make('status')
                    ->badge()
                    ->getStateUsing(fn (\App\Models\Device $record): string => $record->isOnline() ? 'online' : ($record->last_activity_at ? 'offline' : 'waiting'))
                    ->formatStateUsing(fn (string $state): string => match ($state) {
                        'online' => 'Connected',
                        'offline' => 'Offline',
                        default => 'Waiting for connection',
                    })
                    ->color(fn (string $state): string => match ($state) {
                        'online' => 'success',
                        'offline' => 'danger',
                        default => 'warning',
                    }),
                \Filament\Tables\Columns\TextColumn::make('last_activity_at')->label('Last connected')
                    ->dateTime()
                    ->sortable(),
            ])
            ->paginated(false)
            ->toolbarActions([]);
    }
}
