<?php

namespace App\Filament\Tenant\Resources\AttendanceDays\Schemas;

use App\Filament\Forms\Components\LocalDateTimePicker;
use App\Models\AttendanceDay;
use App\Services\Attendance\AttendancePunchEvidence;
use Closure;
use Filament\Forms\Components\Placeholder;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;
use Illuminate\Support\Facades\Validator;

final class AttendanceCorrectionForm
{
    public static function selectedDay(Get $get): ?AttendanceDay
    {
        if (Validator::make(['user_id' => $get('user_id'), 'date' => $get('work_date')], ['user_id' => 'required|integer|min:1', 'date' => 'required|date_format:Y-m-d'])->fails()) {
            return null;
        }
        $key = 'attendance-form-day:'.tenant('id').':'.$get('user_id').':'.$get('work_date');
        if (! request()->attributes->has($key)) {
            request()->attributes->set($key, AttendanceDay::where('user_id', $get('user_id'))->whereDate('work_date', $get('work_date'))->first());
        }

        return request()->attributes->get($key);
    }

    public static function initial(?AttendanceDay $day): array
    {
        $occ = $day?->occurrences()->orderBy('position')->first();

        return ['attendance_occurrence_id' => $occ?->id, 'proposed_in_at' => $occ?->first_in_at,
            'proposed_out_at' => $occ?->assumed_out_at ?? $occ?->last_out_at, 'proposed_status' => null];
    }

    public static function selectionChanged(Get $get, Set $set): void
    {
        $day = self::selectedDay($get);
        $set('entry_mode', $day ? 'correction' : 'manual');
        foreach (self::initial($day) as $key => $value) {
            $set($key, $value);
        }
        foreach (['schedule_id', 'in_time', 'out_time', 'reason'] as $key) {
            $set($key, null);
        }
    }

    public static function punches(Closure $resolveDay): Placeholder
    {
        return Placeholder::make('raw_punches')->label('Recorded punches')->content(function (Get $get, ?AttendanceDay $record) use ($resolveDay) {
            $day = $resolveDay($get, $record);
            if (! $day && (! $get('user_id') || ! $get('work_date'))) {
                return 'Select an employee and work date to load recorded punches.';
            }

            return view('filament.tenant.attendance.punch-evidence', app(AttendancePunchEvidence::class)->read(
                $day?->user_id ?? $get('user_id'), $day?->work_date->toDateString() ?? $get('work_date'), $day, $get('schedule_id')));
        })->columnSpanFull();
    }

    public static function fields(Closure $resolveDay, bool $withPunches = true, bool $withReason = true): array
    {
        return [
            Placeholder::make('current_row')->content(function (Get $get, ?AttendanceDay $record) use ($resolveDay): string {
                $day = $resolveDay($get, $record);

                return $day ? $day->user->name.' · '.$day->work_date->format('d M Y').' · Current result: '.$day->status : 'Select an employee and date.';
            }),
            ...($withPunches ? [self::punches($resolveDay)] : []),
            Select::make('attendance_occurrence_id')->label('Shift occurrence')->options(function (Get $get, ?AttendanceDay $record) use ($resolveDay) {
                return $resolveDay($get, $record)?->occurrences()->with('schedule')->orderBy('position')->get()->mapWithKeys(fn ($o) => [$o->id => ($o->schedule?->name ?? 'Shift').' · '.$o->window_starts_at->format('d M H:i').'–'.$o->window_ends_at->format('d M H:i')]) ?? [];
            })->required(fn (Get $get) => filled($get('proposed_in_at')) || filled($get('proposed_out_at')))->live()
                ->afterStateUpdated(function ($state, Get $get, Set $set, ?AttendanceDay $record) use ($resolveDay) {
                    $occ = $resolveDay($get, $record)?->occurrences()->find($state);
                    $set('proposed_in_at', $occ?->first_in_at);
                    $set('proposed_out_at', $occ?->assumed_out_at ?? $occ?->last_out_at);
                }),
            LocalDateTimePicker::make('proposed_in_at')->label('Actual check-in')->seconds(false),
            LocalDateTimePicker::make('proposed_out_at')->label('Actual checkout')->seconds(false)->after('proposed_in_at'),
            Select::make('proposed_status')->label('Status override')->placeholder('Calculate automatically from corrected times')->options(['present' => 'Present', 'half_day' => 'Half day', 'absent' => 'Absent', 'off' => 'Off', 'holiday' => 'Holiday'])->helperText('Leave blank to calculate status from the selected shift rules.'),
            ...($withReason ? [Textarea::make('reason')->required()->maxLength(2000)] : []),
        ];
    }
}
