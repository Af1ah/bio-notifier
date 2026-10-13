<?php

namespace App\Filament\Tenant\Resources\DeviceResource\RelationManagers;

use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\ViewAction;
use Filament\Forms\Components\TextInput;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

class AttendanceLogsRelationManager extends RelationManager
{
    protected static string $relationship = 'attendanceLogs';

    protected static ?string $title = 'Attendance logs';

    public function form(Schema $schema): Schema
    {
        return $schema
            ->components([
                TextInput::make('pin')
                    ->required()
                    ->maxLength(255),
            ]);
    }

    public function table(Table $table): Table
    {
        return $table
            ->poll('30s')
            ->recordTitleAttribute('pin')
            ->columns([
                TextColumn::make('pin')
                    ->label('User PIN')
                    ->searchable(),
                TextColumn::make('user.name')
                    ->label('User Name')
                    ->placeholder('Unknown'),
                TextColumn::make('punched_at')
                    ->dateTime()
                    ->sortable(),
                TextColumn::make('status')
                    ->badge()
                    ->formatStateUsing(fn ($state) => match ($state) {
                        0 => 'Check In',
                        1 => 'Check Out',
                        default => 'Unknown',
                    })
                    ->color(fn ($state): string => match ($state) {
                        0 => 'success',
                        1 => 'danger',
                        default => 'gray',
                    }),
                TextColumn::make('verify_type_label')
                    ->label('Verify Type'),
            ])
            ->defaultSort('punched_at', 'desc')
            ->filters([
                //
            ])
            ->headerActions([
                //
            ])
            ->recordActions([
                ViewAction::make(),
            ])
            ->toolbarActions([]);
    }
}
