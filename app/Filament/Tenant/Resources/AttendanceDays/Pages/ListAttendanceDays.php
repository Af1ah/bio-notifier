<?php

namespace App\Filament\Tenant\Resources\AttendanceDays\Pages;

use App\Filament\Tenant\Resources\AttendanceDays\AttendanceDayResource;
use App\Models\Schedule;
use App\Models\User;
use App\Services\Attendance\AttendanceEntryService;
use App\Services\Attendance\AttendanceRateLimiter;
use Filament\Actions\Action;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Placeholder;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TimePicker;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Group;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ListRecords;
use Filament\Schemas\Schema;
use Illuminate\Validation\ValidationException;

class ListAttendanceDays extends ListRecords
{
    protected static string $resource = AttendanceDayResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Action::make('manualAttendance')->label('Add manual attendance')->icon('heroicon-o-plus')
                ->visible(fn () => auth()->user()?->privilege === 14)->modalWidth('lg')->modalDescription('Choose the work date, shift and time range. The date is applied automatically to both times.')
                ->schema([Group::make([
                    Select::make('user_id')->label('Employee')->options(fn () => User::employees()->orderBy('name')->pluck('name', 'id'))->searchable()->required()->columnSpan(1),
                    DatePicker::make('work_date')->label('Work date')->default(today())->required()->live()->columnSpan(1),
                    Select::make('schedule_id')->label('Shift')->options(fn () => Schedule::where('status', true)->orderBy('name')->pluck('name', 'id'))->searchable()->required()->live()->columnSpan(1),
                    Placeholder::make('shift_window')->label('Shift check')->content(function (Get $get): string {
                        if (! $get('schedule_id') || ! $get('work_date')) return 'Choose a work date and shift.';
                        $rule = Schedule::find($get('schedule_id'))?->activeRuleRevision(\Carbon\Carbon::parse($get('work_date')));
                        if (! $rule) return 'No active rule for this work date.';
                        $overnight = (int) $rule->end_day_offset > 0 || $rule->end_time <= $rule->start_time;
                        return ($overnight ? 'Night shift' : 'Day shift').' · '.$rule->start_time.'–'.$rule->end_time.($overnight ? ' next day' : ' same day');
                    })->columnSpan(1),
                    TimePicker::make('in_time')->label('Check-in')->seconds(false)->required()->columnSpan(1),
                    TimePicker::make('out_time')->label('Checkout')->seconds(false)->required()->columnSpan(1),
                    Textarea::make('reason')->required()->maxLength(2000)->rows(3)->columnSpanFull(),
                ])->columns(2)])->action(function (array $data, Schema $schema) {
                    try {
                        app(AttendanceRateLimiter::class)->ensure('manual_entry', 30);
                        app(AttendanceEntryService::class)->add(auth()->user(), $data);
                    } catch (ValidationException $exception) {
                        $errors = [];
                        foreach ($exception->errors() as $field => $messages) {
                            $errors[$schema->getStatePath().'.'.$field] = $messages;
                        }throw ValidationException::withMessages($errors);
                    }
                    Notification::make()->title('Manual attendance calculated')->body('Review its attendance approval before payroll.')->success()->send();
                }),
        ];
    }
}
