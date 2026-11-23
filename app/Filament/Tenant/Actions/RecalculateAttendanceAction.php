<?php

namespace App\Filament\Tenant\Actions;

use App\Models\User;
use App\Services\Attendance\AttendanceRateLimiter;
use App\Services\Attendance\AttendanceRunQueue;
use Carbon\Carbon;
use Filament\Actions\Action;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Select;
use Filament\Notifications\Notification;
use Filament\Schemas\Schema;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Validation\ValidationException;

final class RecalculateAttendanceAction
{
    public static function make(): Action
    {
        return Action::make('recalculate')->label('Recalculate')->icon('heroicon-o-arrow-path')->visible(fn () => (bool) auth()->user()?->hasManagementAccess())
            ->modalDescription('Calculations run one organisation at a time. Choose up to 31 days.')
            ->modalSubmitActionLabel('Queue calculation')->schema([
                    DatePicker::make('from_date')->required()->default(today()), DatePicker::make('to_date')->required()->default(today())->afterOrEqual('from_date')->helperText('Maximum range: 31 days, including both dates.'), Select::make('user_ids')->label('Employees (blank means all)')->options(fn () => User::employees()->pluck('name', 'id'))->multiple()->searchable(),
                ])->action(function (array $data, Schema $schema) {
                    abort_unless((bool) auth()->user()?->hasManagementAccess(), 403);
                    $from = Carbon::parse($data['from_date']);
                    $to = Carbon::parse($data['to_date']);
                    if ($to->lt($from)) {
                        self::rejectCalculation($schema, 'to_date', 'The end date must be on or after the start date.');
                    }
                    if ($from->diffInDays($to) > 30) {
                        self::rejectCalculation($schema, 'to_date', 'Maximum range is 31 days.');
                    }$ids = User::employees()->when($data['user_ids'], fn ($query, $ids) => $query->whereIn('id', $ids))->pluck('id')->all();
                    if (count($ids) * ($from->diffInDays($to) + 1) > 10000) {
                        self::rejectCalculation($schema, 'user_ids', 'Maximum workload is 10,000 employee-days.');
                    }
                    try {
                        app(AttendanceRateLimiter::class)->ensure('recalculation', 5);
                    } catch (ValidationException $exception) {
                        self::rejectCalculation($schema, 'from_date', implode(' ', $exception->validator->errors()->all()));
                    }
                    try {
                        $run = app(AttendanceRunQueue::class)->submit(tenancy()->tenant, $from, $to, $data['user_ids'] ?? []);
                    } catch (ValidationException $exception) {
                        self::rejectCalculation($schema, 'from_date', implode(' ', $exception->validator->errors()->all()));
                    } catch (LockTimeoutException $exception) {
                        self::rejectCalculation($schema, 'from_date', 'Another request is being queued. Try again in a moment.');
                    }
                    Notification::make()->title('Recalculation queued')->body("{$run->total} employee-days")->success()->send();
                });
    }

    private static function rejectCalculation(Schema $schema, string $field, string $message): never
    {
        Notification::make()->title('Calculation not queued')->body($message)->danger()->send();
        // Action fields live under mountedActions.{index}.data, not at the page root.
        throw ValidationException::withMessages([$schema->getStatePath().'.'.$field => $message]);
    }
}
