<?php

namespace App\Filament\Tenant\Widgets;

use App\Models\Device;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Filament\Widgets\TableWidget;

class DeviceStatus extends TableWidget
{
    public static function canView(): bool
    {
        return (bool) auth('web')->user()?->canManageDevices();
    }

    protected ?string $pollingInterval = '30s';

    protected int|string|array $columnSpan = 'full';

    protected static ?int $sort = 4;

    public function table(Table $table): Table
    {
        return $table
            ->query(Device::query())
            ->columns([
                TextColumn::make('name')
                    ->searchable()
                    ->sortable(),
                TextColumn::make('type')
                    ->searchable()
                    ->sortable(),
                TextColumn::make('status')
                    ->badge()
                    ->getStateUsing(fn (Device $record): string => $record->isOnline() ? 'online' : ($record->last_activity_at ? 'offline' : 'waiting'))
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
                TextColumn::make('last_activity_at')->label('Last connected')
                    ->dateTime()
                    ->sortable(),
            ])
            ->defaultSort('name')
            ->paginated([10])
            ->defaultPaginationPageOption(10)
            ->toolbarActions([]);
    }
}
