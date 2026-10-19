<?php

namespace App\Filament\Tenant\Resources\DeviceResource\RelationManagers;

use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

class DeviceCommandsRelationManager extends RelationManager
{
    protected static string $relationship = 'commands';

    protected static ?string $title = 'Device actions';

    public function table(Table $table): Table
    {
        return $table->poll('15s')->columns([
            TextColumn::make('command_type')->label('Action')->formatStateUsing(fn ($state) => match ($state) {
                'info' => 'Refresh device details', 'check' => 'Check connection', 'query_users', 'sync_users' => 'Read device users',
                default => ucfirst(str_replace('_', ' ', $state)),
            }),
            TextColumn::make('status')->badge()->formatStateUsing(fn ($state) => match ($state) {
                'pending' => 'Queued', 'sent' => 'Waiting for response', 'acknowledged' => 'Completed',
                'failed' => 'Could not complete', 'outcome_unknown' => 'Needs review', 'expired' => 'Device did not respond',
                default => ucfirst($state),
            })->color(fn ($state) => match ($state) {
                'acknowledged' => 'success', 'failed', 'outcome_unknown', 'expired' => 'danger', default => 'warning',
            }),
            TextColumn::make('created_at')->label('Requested')->dateTime(timezone: fn () => \App\Services\Support\TenantTimezone::get())->sortable(),
            TextColumn::make('acknowledged_at')->label('Completed')->dateTime(timezone: fn () => \App\Services\Support\TenantTimezone::get())->placeholder('—'),
        ])->defaultSort('created_at', 'desc')->emptyStateHeading('No device actions yet')
            ->emptyStateDescription('Use the buttons above to check the connection or read device details.')
            ->recordActions([])->toolbarActions([]);
    }
}
