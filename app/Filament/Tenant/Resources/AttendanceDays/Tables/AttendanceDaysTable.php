<?php

namespace App\Filament\Tenant\Resources\AttendanceDays\Tables;

use App\Filament\Tenant\Resources\AttendanceDays\Schemas\AttendanceCorrectionForm;
use App\Models\AttendanceDay;
use App\Models\Schedule;
use App\Services\Attendance\AttendanceBaselineService;
use App\Services\Attendance\AttendanceEntryService;
use App\Services\Attendance\AttendanceRateLimiter;
use Filament\Actions\Action;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Hidden;
use Filament\Forms\Components\Placeholder;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Validation\ValidationException;

class AttendanceDaysTable
{
    public static function configure(Table $table): Table
    {
        return $table->modifyQueryUsing(fn ($query) => $query->with(['user', 'compensatoryAward'])->withExists(['approvals as has_pending_attendance_approval' => fn ($q) => $q->where('type', 'attendance')->where('state', 'pending')]))->columns([
            TextColumn::make('user.name')->label('Employee')->searchable(),
            TextColumn::make('work_date')->label('Date')->date()->sortable(),
            TextColumn::make('occurrences.schedule.name')->label('Shift')->badge(),
            TextColumn::make('status')->label('Attendance')->badge()->formatStateUsing(fn (string $state, AttendanceDay $record): string => $record->has_pending_attendance_approval ? 'Waiting for approval' : match ($state) {
                'present' => 'Present', 'half_day' => 'Half day', 'absent' => 'Absent', 'off' => 'Off', 'holiday' => 'Holiday', 'pending', 'pending_approval' => 'Needs review', 'no_shift' => 'No shift', default => str($state)->replace('_', ' ')->title(),
            })->color(fn (string $state, AttendanceDay $record): string => $record->has_pending_attendance_approval ? 'warning' : match ($state) {
                'present' => 'success', 'half_day', 'pending', 'pending_approval' => 'warning', 'absent' => 'danger', default => 'gray',
            }),
            TextColumn::make('worked_time')->label('Worked time')->state(function (AttendanceDay $record): string {
                $minutes = $record->approved_worked_minutes ?: $record->candidate_worked_minutes;

                return intdiv((int) $minutes, 60).'h '.((int) $minutes % 60).'m';
            }),
            TextColumn::make('candidate_overtime_minutes')->label('Overtime')->formatStateUsing(fn ($state) => intdiv((int) $state, 60).'h '.((int) $state % 60).'m')->toggleable(isToggledHiddenByDefault: true),
            TextColumn::make('source')->label('Source')->badge()->toggleable(isToggledHiddenByDefault: true),
            TextColumn::make('approved_worked_minutes')->label('Payroll-approved minutes')->toggleable(isToggledHiddenByDefault: true),
            TextColumn::make('candidate_worked_minutes')->label('Calculated minutes')->toggleable(isToggledHiddenByDefault: true),
            TextColumn::make('calculated_at')->label('Last calculated')->since()->toggleable(isToggledHiddenByDefault: true),
        ])->filters([
            Filter::make('work_date')->schema([DatePicker::make('from')->label('From date'), DatePicker::make('to')->label('To date')->afterOrEqual('from')])
                ->query(fn ($query, array $data) => $query->when($data['from'] ?? null, fn ($q, $v) => $q->whereDate('work_date', '>=', $v))->when($data['to'] ?? null, fn ($q, $v) => $q->whereDate('work_date', '<=', $v))),
            SelectFilter::make('source')->options(['device' => 'Device', 'manual' => 'Manual', 'mixed' => 'Device + manual correction']),
            SelectFilter::make('shift')->label('Shift')->options(fn () => Schedule::orderBy('name')->pluck('name', 'id'))->searchable()
                ->query(fn ($query, array $data) => $query->when($data['value'] ?? null, fn ($q, $v) => $q->whereHas('occurrences', fn ($occ) => $occ->where('schedule_id', $v)))),
        ])->recordActions([
            ...CompensatoryAttendanceActions::actions(),
            Action::make('correct')->label('Correct')->icon('heroicon-o-pencil')->modalDescription('The original row and device punches are preserved. Corrected times are calculated using the selected shift and take effect after approval.')
                ->fillForm(fn (AttendanceDay $record): array => AttendanceCorrectionForm::initial($record))
                ->schema(AttendanceCorrectionForm::fields(fn (Get $get, ?AttendanceDay $record) => $record))->action(function (AttendanceDay $record, array $data, Schema $schema) {
                    try {
                        app(AttendanceRateLimiter::class)->ensure('correction', 30);
                        app(AttendanceEntryService::class)->correct($record, auth()->user(), $data);
                    } catch (ValidationException $exception) {
                        $errors = [];
                        foreach ($exception->errors() as $field => $messages) {
                            $errors[$schema->getStatePath().'.'.$field] = $messages;
                        }throw ValidationException::withMessages($errors);
                    }
                }),
            Action::make('reviewLegacy')->label('Review legacy approval')->visible(fn (AttendanceDay $record) => empty($record->explanation['input_signature']) && $record->approvals()->where('state', 'approved')->exists())
                ->modalDescription('Review the original approved result against today’s available shift and punch evidence. This records a new HR-reviewed baseline; it does not claim to reconstruct the historical inputs.')
                ->fillForm(fn (AttendanceDay $record) => ['reviewed_signature' => app(AttendanceBaselineService::class)->evidence($record)['signature']])
                ->schema([Placeholder::make('original')->content(fn (AttendanceDay $record) => $record->status.' · '.$record->approved_worked_minutes.' approved minutes · revision '.$record->calculation_revision),
                    Placeholder::make('evidence')->content(fn (AttendanceDay $record) => collect(app(AttendanceBaselineService::class)->evidence($record)['punches'])->map(fn ($p) => $p['punched_at'].' · '.($p['status'] === 0 ? 'IN' : 'OUT'))->implode(' | ') ?: 'No punches in the review window. Verify the original supporting records.'),
                    Hidden::make('reviewed_signature'), Toggle::make('confirmed')->label('I reviewed the original approval and confirm it against the available evidence')->rule('accepted')->required(), Textarea::make('reason')->required()])
                ->action(fn (AttendanceDay $record, array $data) => app(AttendanceBaselineService::class)->adopt($record, auth()->user(), $data['reason'], $data['reviewed_signature'], $data['confirmed'])),
        ])->defaultSort('work_date', 'desc');
    }
}
