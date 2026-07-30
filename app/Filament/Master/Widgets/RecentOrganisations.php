<?php

namespace App\Filament\Master\Widgets;

use Filament\Actions\BulkActionGroup;
use Filament\Tables\Table;
use Filament\Widgets\TableWidget;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

class RecentOrganisations extends TableWidget
{
    protected int | string | array $columnSpan = 1;
    protected static ?int $sort = 2;

    public function table(Table $table): Table
    {
        return $table
            ->query(\App\Models\Organisation::query()->latest()->limit(5))
            ->columns([
                \Filament\Tables\Columns\TextColumn::make('name')
                    ->searchable()
                    ->sortable(),
                \Filament\Tables\Columns\TextColumn::make('email')
                    ->searchable(),
                \Filament\Tables\Columns\TextColumn::make('created_at')
                    ->dateTime()
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->paginated(false)
            ->toolbarActions([]);
    }
}
