<?php

namespace App\Filament\Tenant\Resources\AttendanceDays\Tables;

use App\Models\AttendanceApproval;
use App\Models\AttendanceCorrection;
use App\Services\Attendance\AttendanceRateLimiter;
use Filament\Actions\Action;
use Filament\Forms\Components\DateTimePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

class AttendanceDaysTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('user.name')->searchable(), TextColumn::make('work_date')->date()->sortable(), TextColumn::make('status')->formatStateUsing(fn ($state) => in_array($state, ['holiday', 'off'], true) ? 'Off' : $state)->badge(), TextColumn::make('candidate_worked_minutes')->label('Candidate min')->sortable(), TextColumn::make('approved_worked_minutes')->label('Approved min'), TextColumn::make('candidate_overtime_minutes')->label('OT min'), TextColumn::make('calculated_at')->since(),
            ])
            ->filters([
                //
            ])
            ->recordActions([
                Action::make('correct')->label('Correct')->icon('heroicon-o-pencil')->schema([DateTimePicker::make('proposed_in_at'), DateTimePicker::make('proposed_out_at'), Select::make('proposed_status')->options(['present' => 'Present', 'half_day' => 'Half day', 'absent' => 'Absent'])->nullable(), Textarea::make('reason')->required()])->action(function ($record, array $data) {
                    app(AttendanceRateLimiter::class)->ensure('correction', 30);
                    $correction = AttendanceCorrection::create($data + ['attendance_day_id' => $record->id, 'created_by_user_id' => auth()->id(), 'state' => 'pending']);
                    AttendanceApproval::create(['attendance_day_id' => $record->id, 'type' => 'attendance', 'state' => 'pending', 'calculation_revision' => $record->calculation_revision, 'requested_by_user_id' => auth()->id(), 'proposal' => ['reason' => 'manual_correction', 'correction_id' => $correction->id] + $data]);
                }),
            ])
            ->defaultSort('work_date', 'desc');
    }
}
