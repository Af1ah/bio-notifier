<?php

namespace App\Filament\Tenant\Resources\AttendanceDays\Tables;

use App\Models\AttendanceDay;
use App\Models\Schedule;
use App\Services\Attendance\AttendanceBaselineService;
use App\Services\Attendance\AttendanceEntryService;
use App\Services\Attendance\AttendanceRateLimiter;
use Filament\Actions\Action;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\DateTimePicker;
use Filament\Forms\Components\Hidden;
use Filament\Forms\Components\Placeholder;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;
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
        return $table->modifyQueryUsing(fn ($query) => $query->with(['user', 'compensatoryAward']))->columns([
            TextColumn::make('user.name')->label('Employee')->searchable(), TextColumn::make('work_date')->date()->sortable(),
            TextColumn::make('occurrences.schedule.name')->label('Shifts')->badge(),
            ...CompensatoryAttendanceActions::columns(),
            TextColumn::make('source')->label('Source')->badge(), TextColumn::make('status')->badge(),
            TextColumn::make('candidate_worked_minutes')->label('Calculated minutes')->sortable(),
            TextColumn::make('approved_worked_minutes')->label('Approved minutes'), TextColumn::make('candidate_overtime_minutes')->label('OT minutes'), TextColumn::make('calculated_at')->since(),
        ])->filters([
            Filter::make('work_date')->schema([DatePicker::make('from')->label('From date'), DatePicker::make('to')->label('To date')->afterOrEqual('from')])
                ->query(fn ($query, array $data) => $query->when($data['from'] ?? null, fn ($q, $v) => $q->whereDate('work_date', '>=', $v))->when($data['to'] ?? null, fn ($q, $v) => $q->whereDate('work_date', '<=', $v))),
            SelectFilter::make('source')->options(['device' => 'Device', 'manual' => 'Manual', 'mixed' => 'Device + manual correction']),
            SelectFilter::make('shift')->label('Shift')->options(fn () => Schedule::orderBy('name')->pluck('name', 'id'))->searchable()
                ->query(fn ($query, array $data) => $query->when($data['value'] ?? null, fn ($q, $v) => $q->whereHas('occurrences', fn ($occ) => $occ->where('schedule_id', $v)))),
        ])->recordActions([
            ...CompensatoryAttendanceActions::actions(),
            Action::make('correct')->label('Correct')->icon('heroicon-o-pencil')->modalDescription('The original row and device punches are preserved. Corrected times are calculated using the selected shift and take effect after approval.')
                ->fillForm(function (AttendanceDay $record): array {
                    $occ = $record->occurrences()->orderBy('position')->first();

                    return ['attendance_occurrence_id' => $occ?->id, 'proposed_in_at' => $occ?->first_in_at, 'proposed_out_at' => $occ?->assumed_out_at ?? $occ?->last_out_at, 'proposed_status' => null];
                })->schema([
                    Placeholder::make('current_row')->content(fn (AttendanceDay $record) => $record->user->name.' · '.$record->work_date->format('d M Y').' · Original status: '.$record->status.' · '.$record->candidate_worked_minutes.' minutes'),
                    Select::make('attendance_occurrence_id')->label('Shift occurrence')->options(fn (AttendanceDay $record) => $record->occurrences()->with('schedule')->orderBy('position')->get()->mapWithKeys(fn ($o) => [$o->id => ($o->schedule?->name ?? 'Shift').' · '.$o->window_starts_at->format('d M H:i').'–'.$o->window_ends_at->format('d M H:i')]))->required(fn (Get $get) => filled($get('proposed_in_at')) || filled($get('proposed_out_at')))->live()
                        ->afterStateUpdated(function ($state, Set $set, AttendanceDay $record) {
                            $occ = $record->occurrences()->find($state);
                            $set('proposed_in_at', $occ?->first_in_at);
                            $set('proposed_out_at', $occ?->assumed_out_at ?? $occ?->last_out_at);
                        }),
                    DateTimePicker::make('proposed_in_at')->label('Actual check-in')->seconds(false), DateTimePicker::make('proposed_out_at')->label('Actual checkout')->seconds(false)->after('proposed_in_at'),
                    Select::make('proposed_status')->label('Status override')->placeholder('Calculate automatically from corrected times')->options(['present' => 'Present', 'half_day' => 'Half day', 'absent' => 'Absent', 'off' => 'Off', 'holiday' => 'Holiday'])->helperText('Leave blank to calculate status from the selected shift rules.'),
                    Textarea::make('reason')->required()->maxLength(2000),
                ])->action(function (AttendanceDay $record, array $data, Schema $schema) {
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
