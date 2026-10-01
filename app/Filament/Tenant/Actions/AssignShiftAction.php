<?php

namespace App\Filament\Tenant\Actions;

use App\Models\Schedule;
use App\Services\Attendance\ShiftAssignmentService;
use Filament\Actions\Action;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Select;
use Filament\Notifications\Notification;

class AssignShiftAction
{
    public static function make(string $ownerType): Action
    {
        return Action::make('assignShift')->label('Assign shift')->icon('heroicon-o-clock')->schema([
            Select::make('schedule_ids')->label('Shift')->options(fn () => Schedule::where('status', true)->pluck('name', 'id'))->multiple()->minItems(1)->required()->searchable()->preload()->live(),
            Select::make('daily_policy_schedule_id')->label('Daily rules source')->options(fn () => Schedule::where('status', true)->pluck('name', 'id'))->visible(fn ($get) => count($get('schedule_ids') ?? []) > 1)->required(fn ($get) => count($get('schedule_ids') ?? []) > 1),
            DatePicker::make('effective_from')->required()->default(today()), DatePicker::make('effective_to')->rule('after_or_equal:effective_from'),
        ])->action(function ($record, array $data) use ($ownerType) {
            app(ShiftAssignmentService::class)->assign($ownerType, $record, $data);
            Notification::make()->title('Shift assigned')->success()->send();
        });
    }
}
