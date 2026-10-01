<?php

namespace App\Filament\Tenant\Resources\AttendanceCalculationRuns\Tables;

use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

class AttendanceCalculationRunsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('run_key')->copyable()->limit(12), TextColumn::make('from_date')->date(), TextColumn::make('to_date')->date(), TextColumn::make('state')->badge(), TextColumn::make('total'), TextColumn::make('completed'), TextColumn::make('failed'), TextColumn::make('created_at')->since(),
            ])
            ->filters([
                //
            ])
            ->defaultSort('created_at', 'desc');
    }
}
