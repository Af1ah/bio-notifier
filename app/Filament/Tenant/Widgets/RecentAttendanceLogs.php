<?php

namespace App\Filament\Tenant\Widgets;

use Filament\Actions\BulkActionGroup;
use Filament\Tables\Table;
use Filament\Widgets\TableWidget;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

class RecentAttendanceLogs extends TableWidget
{
    protected int | string | array $columnSpan = 1;
    protected static ?int $sort = 2;

    public function table(Table $table): Table
    {
        return $table
            ->query(\App\Models\AttendanceLog::query()->with('user', 'device')->latest('punched_at')->limit(5))
            ->columns([
                \Filament\Tables\Columns\TextColumn::make('user.name')
                    ->label('Name')
                    ->searchable()
                    ->sortable(),
                \Filament\Tables\Columns\TextColumn::make('status_label')
                    ->label('State')
                    ->badge(),
                \Filament\Tables\Columns\TextColumn::make('punched_at')
                    ->label('Time')
                    ->time()
                    ->sortable(),
            ])
            ->paginated(false)
            ->toolbarActions([]);
    }
}
