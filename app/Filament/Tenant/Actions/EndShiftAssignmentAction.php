<?php

namespace App\Filament\Tenant\Actions;

use App\Services\Attendance\ShiftAssignmentService;
use Filament\Actions\Action;
use Filament\Forms\Components\DatePicker;
use Filament\Notifications\Notification;

class EndShiftAssignmentAction
{
    public static function make(string $type): Action
    {
        return Action::make('endShiftAssignment')->label('End shift assignment')->color('gray')->schema([DatePicker::make('last_day')->required()->default(today())])->action(function ($record, array $data) use ($type) {
            app(ShiftAssignmentService::class)->end($type, $record, $data['last_day']);
            Notification::make()->title('Assignment ended')->success()->send();
        });
    }
}
