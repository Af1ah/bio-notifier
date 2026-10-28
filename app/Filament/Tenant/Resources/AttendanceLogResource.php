<?php

namespace App\Filament\Tenant\Resources;

use App\Filament\Forms\Components\LocalDateTimePicker;
use App\Filament\Tenant\Resources\AttendanceLogResource\Pages;
use App\Models\AttendanceLog;
use App\Services\Support\TenantTimezone;
use Filament\Actions\ViewAction;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;

class AttendanceLogResource extends Resource
{
    protected static ?string $model = AttendanceLog::class;

    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-clipboard-document-list';

    protected static \UnitEnum|string|null $navigationGroup = 'Device Management';

    protected static ?int $navigationSort = 99;

    public static function canDelete(Model $record): bool
    {
        return false;
    }

    public static function canDeleteAny(): bool
    {
        return false;
    }

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            Section::make('Attendance Details')
                ->schema([
                    Select::make('device_id')
                        ->relationship('device', 'serial_number')
                        ->required(),
                    TextInput::make('pin')
                        ->required(),
                    LocalDateTimePicker::make('punched_at')
                        ->timezone(fn (): string => TenantTimezone::get())
                        ->required(),
                    Select::make('status')
                        ->options([
                            0 => 'Check In',
                            1 => 'Check Out',
                            2 => 'Break Out',
                            3 => 'Break In',
                            4 => 'OT In',
                            5 => 'OT Out',
                            255 => 'INOUT',
                        ]),
                    Select::make('verify_type')
                        ->options([
                            0 => 'Password',
                            1 => 'Fingerprint',
                            2 => 'Card',
                            15 => 'Face',
                        ]),
                ])
                ->columns(2)
                ->columnSpanFull(),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('device.name')
                    ->sortable(),
                Tables\Columns\TextColumn::make('pin')
                    ->label('User PIN')
                    ->searchable(),
                Tables\Columns\TextColumn::make('user.name')
                    ->label('User Name')
                    ->placeholder('Unknown'),
                Tables\Columns\TextColumn::make('punched_at')
                    ->dateTime()
                    ->timezone(fn (): string => TenantTimezone::get())
                    ->sortable(),
                Tables\Columns\TextColumn::make('status')
                    ->badge()
                    ->description(fn (AttendanceLog $record): ?string => $record->attendance_status === (int) $record->status ? null : match ($record->attendance_status) {
                        0 => 'Attendance: Entry', 1 => 'Attendance: Exit', 255 => 'Attendance: Unspecified', default => null
                    })
                    ->formatStateUsing(fn ($state) => match (is_numeric($state) ? (int) $state : null) {
                        0 => 'Check In',
                        1 => 'Check Out',
                        2 => 'Break Out',
                        3 => 'Break In',
                        4 => 'OT In',
                        5 => 'OT Out',
                        255 => 'INOUT',
                        default => 'Unknown',
                    })
                    ->color(fn ($state): string => match (is_numeric($state) ? (int) $state : null) {
                        0 => 'success',
                        1 => 'danger',
                        default => 'warning',
                    }),
                Tables\Columns\TextColumn::make('verify_type_label')
                    ->label('Verify Type'),
            ])
            ->defaultSort('punched_at', 'desc')
            ->filters([
                Tables\Filters\SelectFilter::make('device')
                    ->relationship('device', 'serial_number'),
                Tables\Filters\SelectFilter::make('status')
                    ->options([
                        0 => 'Check In',
                        1 => 'Check Out',
                        2 => 'Break Out',
                        3 => 'Break In',
                        4 => 'OT In',
                        5 => 'OT Out',
                        255 => 'INOUT',
                    ]),
                Tables\Filters\Filter::make('punched_at')
                    ->schema([
                        DatePicker::make('from'),
                        DatePicker::make('until'),
                    ])
                    ->query(function ($query, array $data) {
                        return $query
                            ->when($data['from'], fn ($q, $date) => $q->whereDate('punched_at', '>=', $date))
                            ->when($data['until'], fn ($q, $date) => $q->whereDate('punched_at', '<=', $date));
                    }),
            ])
            ->recordActions([
                ViewAction::make(),
            ])
            ->toolbarActions([]);
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListAttendanceLogs::route('/'),
            'view' => Pages\ViewAttendanceLog::route('/{record}'),
        ];
    }
}
