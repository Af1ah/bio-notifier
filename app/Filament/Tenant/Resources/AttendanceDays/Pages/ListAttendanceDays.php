<?php

namespace App\Filament\Tenant\Resources\AttendanceDays\Pages;

use App\Filament\Tenant\Resources\AttendanceDays\AttendanceDayResource;
use App\Jobs\RecalculateAttendanceChunk;
use App\Models\AttendanceCalculationRun;
use App\Models\User;
use App\Services\Attendance\AttendanceRateLimiter;
use Carbon\Carbon;
use Filament\Actions\Action;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Select;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ListRecords;
use Filament\Schemas\Schema;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class ListAttendanceDays extends ListRecords
{
    protected static string $resource = AttendanceDayResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Action::make('recalculate')->label('Recalculate')->icon('heroicon-o-arrow-path')
                ->modalDescription('Choose up to 31 days per calculation. For a longer period, calculate one month at a time.')
                ->modalSubmitActionLabel('Queue calculation')->schema([
                DatePicker::make('from_date')->required()->default(today()), DatePicker::make('to_date')->required()->default(today())->afterOrEqual('from_date')->helperText('Maximum range: 31 days, including both dates.'), Select::make('user_ids')->label('Employees (blank means all)')->options(fn () => User::pluck('name', 'id'))->multiple()->searchable(),
            ])->action(function (array $data, Schema $schema) {
                if (AttendanceCalculationRun::whereIn('state', ['queued', 'running'])->exists()) {
                    $this->rejectCalculation($schema, 'from_date', 'Another recalculation is already active for this tenant.');
                }
                $from = Carbon::parse($data['from_date']);
                $to = Carbon::parse($data['to_date']);
                if ($to->lt($from)) {
                    $this->rejectCalculation($schema, 'to_date', 'The end date must be on or after the start date.');
                }
                if ($from->diffInDays($to) > 30) {
                    $this->rejectCalculation($schema, 'to_date', 'Maximum range is 31 days.');
                }$ids = $data['user_ids'] ?: User::pluck('id')->all();
                if (count($ids) * ($from->diffInDays($to) + 1) > 10000) {
                    $this->rejectCalculation($schema, 'user_ids', 'Maximum workload is 10,000 employee-days.');
                }
                try {
                    app(AttendanceRateLimiter::class)->ensure('recalculation', 5);
                } catch (ValidationException $exception) {
                    $this->rejectCalculation($schema, 'from_date', implode(' ', $exception->validator->errors()->all()));
                }
                $run = AttendanceCalculationRun::create(['run_key' => Str::uuid(), 'from_date' => $from, 'to_date' => $to, 'filters' => ['user_ids' => $data['user_ids'] ?? []], 'total' => count($ids) * ($from->diffInDays($to) + 1)]);
                foreach (array_chunk($ids, 100) as $chunk) {
                    RecalculateAttendanceChunk::dispatch(tenancy()->tenant, $run->id, $chunk);
                }Notification::make()->title('Recalculation queued')->body("{$run->total} employee-days")->success()->send();
            }),
        ];
    }

    protected function rejectCalculation(Schema $schema, string $field, string $message): never
    {
        Notification::make()->title('Calculation not queued')->body($message)->danger()->send();
        // Action fields live under mountedActions.{index}.data, not at the page root.
        throw ValidationException::withMessages([$schema->getStatePath().'.'.$field => $message]);
    }
}
