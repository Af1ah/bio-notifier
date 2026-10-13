<?php

namespace App\Filament\Tenant\Resources\DeviceResource\RelationManagers;

use App\Services\Devices\DeviceIntegration;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;

class DeviceUsersRelationManager extends RelationManager
{
    protected static string $relationship = 'deviceUsers';

    protected static ?string $title = 'Device users';

    public static function canViewForRecord(Model $ownerRecord, string $pageClass): bool
    {
        return tenant()?->device_integration === DeviceIntegration::GATEWAY;
    }

    public function table(Table $table): Table
    {
        return $table->columns([
            TextColumn::make('pin')->label('PIN')->searchable(),
            TextColumn::make('name')->searchable(),
            TextColumn::make('card')->label('Card number'),
            TextColumn::make('updated_at')->label('Last received')->dateTime(),
        ])->defaultSort('pin')->recordActions([])->toolbarActions([]);
    }
}
