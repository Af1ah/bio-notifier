<?php

namespace App\Filament\Tenant\Resources\Schedules\Tables;

use App\Services\Attendance\ShiftAssignmentService;
use App\Services\Support\TenantTimezone;
use Filament\Actions\Action;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\DatePicker;
use Filament\Notifications\Notification;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;

class SchedulesTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(function (Builder $query): Builder {
                $today = today(TenantTimezone::get());
                $defaultSet = DB::table('default_shift_assignments')
                    ->join('shift_assignment_sets', 'shift_assignment_sets.id', '=', 'default_shift_assignments.shift_assignment_set_id')
                    ->where('default_shift_assignments.active', true)
                    ->where('shift_assignment_sets.active', true)
                    ->where('default_shift_assignments.effective_from', '<=', $today->copy()->endOfDay())
                    ->where(fn ($q) => $q->whereNull('default_shift_assignments.effective_to')->orWhereDate('default_shift_assignments.effective_to', '>=', $today->toDateString()))
                    ->orderByDesc('default_shift_assignments.effective_from')
                    ->select('shift_assignment_sets.id')->limit(1);

                return $query->select('schedules.*')->addSelect(['current_default' => DB::table('shift_assignment_slots')
                    ->selectRaw('1')->whereColumn('shift_assignment_slots.schedule_id', 'schedules.id')
                    ->where('shift_assignment_set_id', $defaultSet)->limit(1)]);
            })
            ->columns([
                TextColumn::make('name')
                    ->searchable()
                    ->sortable(),
                TextColumn::make('current_default')->label('')->badge()->color('success')
                    ->formatStateUsing(fn ($state) => $state ? 'Default' : null),
                TextColumn::make('latestRule.start_time')->label('Starts')->time('H:i'),
                TextColumn::make('latestRule.end_time')->label('Ends')->time('H:i'),
                TextColumn::make('valid_from')
                    ->date()
                    ->sortable()
                    ->visibleFrom('md'),
                TextColumn::make('valid_to')
                    ->date()
                    ->sortable()
                    ->visibleFrom('md'),
                IconColumn::make('status')
                    ->boolean(),
            ])
            ->filters([
                //
            ])
            ->recordActions([
                Action::make('makeDefault')->label('Make default')->icon('heroicon-o-star')->schema([
                    DatePicker::make('effective_from')->required()->default(today()),
                    DatePicker::make('effective_to')->rule('after_or_equal:effective_from'),
                ])->action(function ($record, array $data) {
                    app(ShiftAssignmentService::class)->setDefault($record, $data['effective_from'], $data['effective_to'] ?? null);
                    Notification::make()->title('Default shift updated')->success()->send();
                }),
                EditAction::make(),
            ])
            ->toolbarActions([
                BulkActionGroup::make([
                    DeleteBulkAction::make(),
                ]),
            ]);
    }
}
