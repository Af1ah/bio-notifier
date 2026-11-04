<?php

namespace App\Filament\Tenant\Actions;

use App\Models\SalarySlip;
use App\Services\Payroll\SalarySlipService;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Illuminate\Validation\ValidationException;

class SalarySlipActions
{
    public static function make(): array
    {
        return [
            Action::make('recalculate')
                ->label('Recalculate')
                ->icon('heroicon-o-arrow-path')
                ->color('gray')
                ->visible(fn (SalarySlip $record): bool => $record->status === 'draft')
                ->requiresConfirmation()
                ->modalDescription('This replaces the draft values with the latest payroll profile, attendance, leave, and approved overtime data.')
                ->action(fn (SalarySlip $record, Action $action) => self::run($action, fn () => app(SalarySlipService::class)->generate($record->user, $record->period_start)))
                ->successNotificationTitle('Salary slip recalculated'),
            Action::make('review')
                ->label('Mark reviewed')
                ->icon('heroicon-o-clipboard-document-check')
                ->visible(fn (SalarySlip $record): bool => $record->status === 'draft')
                ->requiresConfirmation()
                ->action(fn (SalarySlip $record, Action $action) => self::run($action, fn () => app(SalarySlipService::class)->transition($record, auth()->user(), 'reviewed')))
                ->successNotificationTitle('Salary slip reviewed'),
            Action::make('approve')
                ->label('Approve')
                ->icon('heroicon-o-check-badge')
                ->color('success')
                ->visible(fn (SalarySlip $record): bool => $record->status === 'reviewed')
                ->requiresConfirmation()
                ->action(fn (SalarySlip $record, Action $action) => self::run($action, fn () => app(SalarySlipService::class)->transition($record, auth()->user(), 'approved')))
                ->successNotificationTitle('Salary slip approved'),
            Action::make('markPaid')
                ->label('Mark paid')
                ->icon('heroicon-o-banknotes')
                ->color('success')
                ->visible(fn (SalarySlip $record): bool => $record->status === 'approved')
                ->requiresConfirmation()
                ->action(fn (SalarySlip $record, Action $action) => self::run($action, fn () => app(SalarySlipService::class)->transition($record, auth()->user(), 'paid')))
                ->successNotificationTitle('Salary slip marked paid'),
        ];
    }

    private static function run(Action $action, callable $operation): void
    {
        try {
            $operation();
        } catch (ValidationException $exception) {
            $message = collect($exception->errors())->flatten()->implode(' ');
            if (str_contains($message, 'Manual payroll period requires')) {
                $message .= ' Use Review manual payroll period at the top of this page.';
            }
            Notification::make()->title('Salary slip not updated')->body($message)->danger()->send();
            $action->cancel();
        }
    }
}
