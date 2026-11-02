<?php

namespace App\Filament\Tenant\Resources\AttendanceDays\Schemas;

use App\Filament\Forms\Components\LocalDateTimePicker;
use App\Models\AttendanceCorrection;
use App\Models\AttendanceDay;
use App\Models\User;
use App\Services\Attendance\AttendanceEntryContext;
use App\Services\Attendance\AttendanceEntryService;
use App\Services\Attendance\AttendancePunchEvidence;
use App\Services\Attendance\AttendanceRateLimiter;
use App\Services\Support\TenantTimezone;
use Filament\Actions\Action;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Hidden;
use Filament\Forms\Components\Placeholder;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Notifications\Notification;
use Filament\Schemas\Components\Group;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Schemas\Schema;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

final class AttendanceCorrectionForm
{
    public static function selectedDay(Get $get): ?AttendanceDay
    {
        if (! self::validSelection($get)) {
            return null;
        }

        return AttendanceDay::where('user_id', $get('user_id'))->whereDate('work_date', $get('work_date'))->first();
    }

    private static function validSelection(Get $get): bool
    {
        return Validator::make(['user_id' => $get('user_id'), 'date' => $get('work_date')],
            ['user_id' => 'required|integer|min:1', 'date' => 'required|date_format:Y-m-d'])->passes();
    }

    public static function initial(?AttendanceDay $day): array
    {
        return ['user_id' => $day?->user_id, 'work_date' => $day?->work_date->toDateString() ?? today(TenantTimezone::get())->toDateString(),
            'entry_mode' => $day ? 'correction' : 'manual', 'reason' => null]
            + ($day ? app(AttendanceEntryContext::class)->initial($day->user, $day->work_date->toDateString()) : ['in_time' => null, 'out_time' => null]);
    }

    public static function selectionChanged(Get $get, Set $set): void
    {
        $day = self::selectedDay($get);
        $set('entry_mode', $day ? 'correction' : 'manual');
        $user = self::validSelection($get) ? User::employees()->find($get('user_id')) : null;
        $times = $user ? app(AttendanceEntryContext::class)->initial($user, $get('work_date')) : ['in_time' => null, 'out_time' => null];
        foreach ($times + ['reason' => null] as $key => $value) {
            $set($key, $value);
        }
    }

    public static function action(string $name = 'manualAttendance'): Action
    {
        return Action::make($name)->label('Add or correct attendance')->modalHeading('Add or correct attendance')
            ->icon('heroicon-o-plus')->modalWidth('lg')->modalSubmitActionLabel('Save')
            ->authorize(fn (): bool => (bool) auth()->user()?->hasManagementAccess())
            ->fillForm(fn (?AttendanceDay $record): array => self::initial($record))
            ->schema([Group::make([
                Select::make('user_id')->label('Employee')->options(fn () => User::employees()->orderBy('name')->pluck('name', 'id'))
                    ->searchable()->required()->live()->afterStateUpdated(fn (Get $get, Set $set) => self::selectionChanged($get, $set)),
                DatePicker::make('work_date')->label('Work date')->required()->live()
                    ->afterStateUpdated(fn (Get $get, Set $set) => self::selectionChanged($get, $set)),
                Hidden::make('entry_mode'),
                Placeholder::make('raw_punches')->label('Recorded punches')->columnSpanFull()->content(function (Get $get) {
                    if (! self::validSelection($get)) {
                        return 'Choose an employee and date.';
                    }

                    return view('filament.tenant.attendance.punch-evidence', app(AttendancePunchEvidence::class)->read(
                        $get('user_id'), $get('work_date'), self::selectedDay($get)));
                }),
                Placeholder::make('shift_window')->label('Shift')->columnSpanFull()->content(function (Get $get): string {
                    $user = self::validSelection($get) ? User::employees()->find($get('user_id')) : null;
                    if (! $user) {
                        return 'Assigned automatically.';
                    }
                    $context = app(AttendanceEntryContext::class);
                    if (Validator::make(['in_time' => $get('in_time'), 'out_time' => $get('out_time')],
                        ['in_time' => 'required|date_format:H:i', 'out_time' => 'required|date_format:H:i'])->passes()) {
                        try {
                            $match = $context->match($user, $get('work_date'), $get('in_time'), $get('out_time'));

                            return $match['name'].' · '.$match['in']->format('d M H:i').'–'.$match['out']->format('d M H:i');
                        } catch (ValidationException $exception) {
                            return collect($exception->errors())->flatten()->first();
                        }
                    }
                    $candidates = $context->candidates($user, $get('work_date'));

                    return match (count($candidates)) {
                        0 => 'No assigned or default shift for this date.',
                        1 => $candidates[0]['name'],
                        default => 'Matched automatically from your times.',
                    };
                }),
                LocalDateTimePicker::make('in_time')->date(false)->label('Check-in')->seconds(false)->format('H:i')->rules(['date_format:H:i'])->required()->live(onBlur: true),
                LocalDateTimePicker::make('out_time')->date(false)->label('Checkout')->seconds(false)->format('H:i')->rules(['date_format:H:i'])->required()->live(onBlur: true),
                Textarea::make('reason')->required()->maxLength(2000)->rows(3)->columnSpanFull(),
            ])->columns(2)])
            ->action(function (array $data, Schema $schema): void {
                try {
                    app(AttendanceRateLimiter::class)->ensure(($data['entry_mode'] ?? null) === 'correction' ? 'correction' : 'manual_entry', 30);
                    $result = app(AttendanceEntryService::class)->addOrCorrect(auth()->user(), $data);
                } catch (ValidationException $exception) {
                    $errors = [];
                    foreach ($exception->errors() as $field => $messages) {
                        $errors[$schema->getStatePath().'.'.$field] = $messages;
                    }
                    throw ValidationException::withMessages($errors);
                }
                Notification::make()->title($result instanceof AttendanceCorrection ? 'Correction waiting for approval' : 'Attendance waiting for approval')->success()->send();
            });
    }
}
