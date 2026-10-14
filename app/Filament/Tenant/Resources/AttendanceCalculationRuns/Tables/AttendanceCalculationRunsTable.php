<?php

namespace App\Filament\Tenant\Resources\AttendanceCalculationRuns\Tables;

use Filament\Actions\Action;
use Filament\Forms\Components\Placeholder;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Support\Facades\DB;

class AttendanceCalculationRunsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('run_key')->copyable()->limit(12), TextColumn::make('from_date')->date(), TextColumn::make('to_date')->date(), TextColumn::make('state')->formatStateUsing(fn ($state) => match ($state) {
                    'completed_with_skips' => 'Completed with skips','needs_reconciliation' => 'Historical review required',default => ucfirst($state)
                })->badge(), TextColumn::make('total'), TextColumn::make('completed'), TextColumn::make('skipped')->label('Skipped employee-days'), TextColumn::make('failed'), TextColumn::make('last_error')->label('Review note')->wrap(), TextColumn::make('created_at')->since(),
            ])
            ->filters([
                //
            ])
            ->recordActions([Action::make('showSkipped')->label('Skipped employee-days')->visible(fn ($record) => $record->skipped > 0)->modalSubmitAction(false)->schema([Placeholder::make('skipped')->content(function ($record) {
                $rows = DB::table('attendance_calculation_results')->where('attendance_calculation_run_id', $record->id)->where('outcome', 'skipped')->orderBy('work_date')->limit(100)->get();

                return $rows->map(fn ($r) => 'Employee #'.$r->employee_id_snapshot.' · '.$r->work_date.' · '.($r->reason === 'employee_removed' ? 'Employee removed' : 'Employee excluded'))->implode(' | ').($record->skipped > 100 ? ' (first 100 shown)' : '');
            })])])
            ->defaultSort('created_at', 'desc');
    }
}
