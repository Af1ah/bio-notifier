<?php

namespace App\Filament\Tenant\Resources\AttendanceDays\Pages;

use App\Filament\Tenant\Resources\AttendanceDays\AttendanceDayResource;
use App\Filament\Tenant\Resources\AttendanceDays\Schemas\AttendanceCorrectionForm;
use App\Models\AttendanceCorrection;
use App\Models\AttendanceDay;
use App\Models\Schedule;
use App\Models\User;
use App\Services\Attendance\AttendanceEntryService;
use App\Services\Attendance\AttendanceRateLimiter;
use App\Services\Support\TenantTimezone;
use Carbon\Carbon;
use Filament\Actions\Action;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Hidden;
use Filament\Forms\Components\Placeholder;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TimePicker;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ListRecords;
use Filament\Schemas\Components\Group;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Schemas\Schema;
use Illuminate\Validation\ValidationException;

class ListAttendanceDays extends ListRecords
{
    protected static string $resource = AttendanceDayResource::class;

    protected function getHeaderActions(): array
    {
        $resolveDay = fn (Get $get, ?AttendanceDay $record) => AttendanceCorrectionForm::selectedDay($get);

        return [
            Action::make('manualAttendance')->label('Add or correct attendance')->icon('heroicon-o-plus')
                ->visible(fn () => auth()->user()?->privilege === 14)->modalWidth('lg')->modalDescription('Choose an employee and work date to see recorded punches. Existing attendance is corrected; new attendance is entered manually.')
                ->schema([Group::make([
                    Select::make('user_id')->label('Employee')->options(fn () => User::employees()->orderBy('name')->pluck('name', 'id'))->searchable()->required()->live()->afterStateUpdated(fn (Get $get, Set $set) => AttendanceCorrectionForm::selectionChanged($get, $set))->columnSpan(1),
                    DatePicker::make('work_date')->label('Work date')->default(today(TenantTimezone::get()))->required()->live()->afterStateUpdated(fn (Get $get, Set $set) => AttendanceCorrectionForm::selectionChanged($get, $set))->columnSpan(1),
                    Hidden::make('entry_mode')->default('manual'),
                    AttendanceCorrectionForm::punches($resolveDay),
                    Group::make(AttendanceCorrectionForm::fields($resolveDay, false, false))->visible(fn (Get $get) => AttendanceCorrectionForm::selectedDay($get) !== null)->dehydratedWhenHidden(false)->columnSpanFull(),
                    Group::make([
                        Select::make('schedule_id')->label('Shift')->options(fn () => Schedule::where('status', true)->orderBy('name')->pluck('name', 'id'))->searchable()->required()->live()->columnSpan(1),
                        Placeholder::make('shift_window')->label('Shift check')->content(function (Get $get): string {
                            if (! $get('schedule_id') || ! $get('work_date')) {
                                return 'Choose a work date and shift.';
                            }
                            $rule = Schedule::find($get('schedule_id'))?->activeRuleRevision(Carbon::parse($get('work_date')));
                            if (! $rule) {
                                return 'No active rule for this work date.';
                            }
                            $overnight = (int) $rule->end_day_offset > 0 || $rule->end_time <= $rule->start_time;

                            return ($overnight ? 'Night shift' : 'Day shift').' · '.$rule->start_time.'–'.$rule->end_time.($overnight ? ' next day' : ' same day');
                        })->columnSpan(1),
                        TimePicker::make('in_time')->label('Check-in')->seconds(false)->required()->columnSpan(1),
                        TimePicker::make('out_time')->label('Checkout')->seconds(false)->required()->columnSpan(1),
                    ])->columns(2)->visible(fn (Get $get) => AttendanceCorrectionForm::selectedDay($get) === null)->dehydratedWhenHidden(false)->columnSpanFull(),
                    Textarea::make('reason')->required()->maxLength(2000)->rows(3)->columnSpanFull(),
                ])->columns(2)])->action(function (array $data, Schema $schema) {
                    try {
                        app(AttendanceRateLimiter::class)->ensure(($data['entry_mode'] ?? null) === 'correction' ? 'correction' : 'manual_entry', 30);
                        $result = app(AttendanceEntryService::class)->addOrCorrect(auth()->user(), $data);
                    } catch (ValidationException $exception) {
                        $errors = [];
                        foreach ($exception->errors() as $field => $messages) {
                            $errors[$schema->getStatePath().'.'.$field] = $messages;
                        }throw ValidationException::withMessages($errors);
                    }
                    Notification::make()->title($result instanceof AttendanceCorrection ? 'Correction waiting for approval' : 'Manual attendance calculated')->body('Review its attendance approval before payroll.')->success()->send();
                }),
        ];
    }
}
