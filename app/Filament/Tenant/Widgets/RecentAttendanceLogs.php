<?php

namespace App\Filament\Tenant\Widgets;

use App\Services\Attendance\TodayAttendance;
use App\Services\Support\TenantTimezone;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Filament\Widgets\TableWidget;

class RecentAttendanceLogs extends TableWidget
{
    protected ?string $pollingInterval = '30s';

    protected int|string|array $columnSpan = 1;

    protected static ?int $sort = 2;

    public function table(Table $table): Table
    {
        return $table
            ->query(TodayAttendance::logs()->with('user', 'device')->latest('punched_at')->orderByDesc('id')->limit(5))
            ->columns([
                TextColumn::make('user.name')
                    ->label('Name')
                    ->searchable()
                    ->sortable(),
                TextColumn::make('status_label')
                    ->label('State')
                    ->badge(),
                TextColumn::make('punched_at')
                    ->label('Time')
                    ->time()->timezone(fn () => TenantTimezone::get())
                    ->sortable(),
            ])
            ->paginated(false)
            ->toolbarActions([]);
    }
}
