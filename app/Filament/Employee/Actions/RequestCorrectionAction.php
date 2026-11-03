<?php

namespace App\Filament\Employee\Actions;

use App\Filament\Forms\Components\LocalDateTimePicker;
use App\Models\AttendanceDay;
use App\Services\Attendance\AttendanceEntryContext;
use App\Services\Attendance\AttendanceEntryService;
use App\Services\Attendance\AttendanceRateLimiter;
use App\Services\EmployeePortal\PortalAccess;
use App\Services\Support\TenantTimezone;
use Filament\Actions\Action;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Hidden;
use Filament\Forms\Components\Textarea;
use Filament\Notifications\Notification;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Schemas\Schema;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

final class RequestCorrectionAction
{
    public static function make(): Action
    {
        return Action::make('requestCorrection')->label('Request correction')->icon('heroicon-o-pencil-square')->modalWidth('lg')
            ->modalDescription('HR or Admin will review your request.')
            ->fillForm(function (?AttendanceDay $record): array {
                $employee = PortalAccess::employee();
                abort_if($record && $record->user_id !== $employee->id, 403);
                $date = $record?->work_date->toDateString() ?? today(TenantTimezone::get())->toDateString();
                $day = AttendanceDay::where('user_id', $employee->id)->whereDate('work_date', $date)->first();

                return ['work_date' => $date, 'entry_mode' => $day ? 'correction' : 'manual']
                    + app(AttendanceEntryContext::class)->initial($employee, $date);
            })
            ->schema([
                DatePicker::make('work_date')->label('Date')->required()->maxDate(fn () => today(TenantTimezone::get())->toDateString())->live()
                    ->afterStateUpdated(function ($state, Set $set) {
                        if (! Validator::make(['date' => $state], ['date' => 'required|date_format:Y-m-d'])->passes()) {
                            return;
                        }
                        $employee = PortalAccess::employee();
                        foreach (app(AttendanceEntryContext::class)->initial($employee, $state) as $key => $value) {
                            $set($key, $value);
                        }
                        $set('entry_mode', AttendanceDay::where('user_id', $employee->id)->whereDate('work_date', $state)->exists() ? 'correction' : 'manual');
                    }),
                Hidden::make('entry_mode'),
                LocalDateTimePicker::make('in_time')->label('Check-in')->date(false)->seconds(false)->format('H:i')->required(),
                LocalDateTimePicker::make('out_time')->label('Checkout')->date(false)->seconds(false)->format('H:i')->required(),
                Textarea::make('reason')->required()->maxLength(2000)->rows(3)->columnSpanFull(),
            ])->action(function (array $data, Schema $schema) {
                try {
                    $employee = PortalAccess::employee();
                    Validator::make($data, ['work_date' => 'required|date_format:Y-m-d|before_or_equal:'.today(TenantTimezone::get())->toDateString()])->validate();
                    app(AttendanceRateLimiter::class)->ensure('employee_correction', 10);
                    app(AttendanceEntryService::class)->addOrCorrect($employee, ['user_id' => $employee->id] + $data);
                } catch (ValidationException $exception) {
                    $errors = [];
                    foreach ($exception->errors() as $key => $messages) {
                        $errors[$schema->getStatePath().'.'.$key] = $messages;
                    }
                    throw ValidationException::withMessages($errors);
                }
                Notification::make()->title('Correction requested')->success()->send();
            });
    }
}
