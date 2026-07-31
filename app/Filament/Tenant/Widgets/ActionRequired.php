<?php

namespace App\Filament\Tenant\Widgets;

use Filament\Actions\BulkActionGroup;
use Filament\Tables\Table;
use Filament\Widgets\TableWidget;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

class ActionRequired extends TableWidget
{
    protected ?string $pollingInterval = "30s";

    protected int | string | array $columnSpan = 'full';
    protected static ?int $sort = 5;

    public function table(Table $table): Table
    {
        return $table
            ->query(\App\Models\DeviceCommand::query()->whereIn('status', ['pending', 'failed'])->latest())
            ->columns([
                \Filament\Tables\Columns\TextColumn::make('device.name')
                    ->label('Device')
                    ->searchable()
                    ->sortable(),
                \Filament\Tables\Columns\TextColumn::make('command_type')
                    ->label('Command')
                    ->searchable(),
                \Filament\Tables\Columns\TextColumn::make('status')
                    ->badge()
                    ->color(fn (string $state): string => match ($state) {
                        'failed' => 'danger',
                        'pending' => 'warning',
                        default => 'gray',
                    }),
                \Filament\Tables\Columns\TextColumn::make('created_at')
                    ->dateTime()
                    ->sortable(),
            ])
            ->paginated(false)
            ->toolbarActions([]);
    }
}
