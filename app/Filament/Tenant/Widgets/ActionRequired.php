<?php

namespace App\Filament\Tenant\Widgets;

use App\Models\DeviceCommand;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Filament\Widgets\TableWidget;

class ActionRequired extends TableWidget
{
    public static function canView(): bool
    {
        return (bool) auth('web')->user()?->canManageDevices();
    }

    protected ?string $pollingInterval = '30s';

    protected int|string|array $columnSpan = 'full';

    protected static ?int $sort = 5;

    public function table(Table $table): Table
    {
        return $table
            ->query(DeviceCommand::query()->with('device')->whereIn('status', ['pending', 'failed'])->latest()->orderByDesc('id'))
            ->columns([
                TextColumn::make('device.name')
                    ->label('Device')
                    ->searchable()
                    ->sortable(),
                TextColumn::make('command_type')
                    ->label('Command')
                    ->searchable(),
                TextColumn::make('status')
                    ->badge()
                    ->color(fn (string $state): string => match ($state) {
                        'failed' => 'danger',
                        'pending' => 'warning',
                        default => 'gray',
                    }),
                TextColumn::make('created_at')
                    ->dateTime()
                    ->sortable(),
            ])
            ->paginated([10])
            ->defaultPaginationPageOption(10)
            ->toolbarActions([]);
    }
}
