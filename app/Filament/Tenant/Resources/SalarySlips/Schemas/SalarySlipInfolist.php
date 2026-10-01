<?php

namespace App\Filament\Tenant\Resources\SalarySlips\Schemas;

use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

class SalarySlipInfolist
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make('Employee and period')
                    ->schema([
                        TextEntry::make('user.name')->label('Employee'),
                        TextEntry::make('user.pin')->label('Employee PIN'),
                        TextEntry::make('designation')->placeholder('Not assigned'),
                        TextEntry::make('period_start')->label('Payroll month')->date('F Y'),
                        TextEntry::make('status')->badge()->color(fn ($state): string => match ($state) {
                            'paid', 'approved' => 'success',
                            'reviewed' => 'warning',
                            default => 'gray',
                        }),
                        TextEntry::make('calculated_at')->dateTime('d M Y, h:i A'),
                        TextEntry::make('calculation_snapshot.recalculation_reason')
                            ->label('Attendance changes')->color('warning')
                            ->visible(fn ($record): bool => (bool) ($record->calculation_snapshot['needs_recalculation'] ?? false)),
                        TextEntry::make('calculation_snapshot.pay_basis')
                            ->label('Pay basis')
                            ->formatStateUsing(fn ($state): string => match ($state) {
                                'daily' => 'Daily rate',
                                'hourly' => 'Hourly rate',
                                default => 'Monthly salary',
                            }),
                    ])
                    ->columns(['default' => 1, 'md' => 2, 'xl' => 3]),
                Section::make('Earnings')
                    ->schema([
                        self::money('basic_salary_minor', 'Base pay'),
                        self::money('housing_allowance_minor', 'Housing allowance'),
                        self::money('transport_allowance_minor', 'Transport allowance'),
                        self::money('other_allowance_minor', 'Other allowance'),
                        self::money('overtime_pay_minor', 'Overtime pay'),
                        self::money('gross_pay_minor', 'Gross pay')->weight('bold'),
                    ])
                    ->columns(['default' => 1, 'md' => 2, 'xl' => 3]),
                Section::make('Deductions and net pay')
                    ->schema([
                        TextEntry::make('calculation_snapshot.calendar_day_divisor')->label('Monthly deduction divisor')->suffix(' days')->placeholder('Not recorded'),
                        self::money('attendance_deduction_minor', 'Attendance deduction'),
                        self::money('leave_deduction_minor', 'Leave deduction'),
                        self::money('fixed_deduction_minor', 'Fixed deduction'),
                        self::money('total_deduction_minor', 'Total deduction')->weight('bold'),
                        self::money('net_pay_minor', 'Net pay')->weight('bold')->color('success'),
                    ])
                    ->columns(['default' => 1, 'md' => 2, 'xl' => 3]),
                Section::make('Attendance summary')
                    ->schema([
                        TextEntry::make('scheduled_days')->label('Scheduled days'),
                        TextEntry::make('full_days')->label('Full days'),
                        TextEntry::make('half_days')->label('Half days'),
                        TextEntry::make('absent_days')->label('Absent days')->formatStateUsing(fn ($state, $record) => $state + (($record->calculation_snapshot['leave_other_half_absent_days'] ?? 0) / 2)),
                        TextEntry::make('paid_leave_half_units')->label('Paid leave')->formatStateUsing(fn ($state): string => number_format(((int) $state) / 2, 1).' days'),
                        TextEntry::make('unpaid_leave_half_units')->label('Unpaid leave')->formatStateUsing(fn ($state): string => number_format(((int) $state) / 2, 1).' days'),
                        TextEntry::make('approved_overtime_minutes')->label('Approved overtime')->suffix(' minutes'),
                    ])
                    ->columns(['default' => 2, 'md' => 4]),
                Section::make('Approval trail')
                    ->schema([
                        TextEntry::make('reviewedBy.name')->label('Reviewed by')->placeholder('Not reviewed'),
                        TextEntry::make('reviewed_at')->label('Reviewed at')->dateTime('d M Y, h:i A')->placeholder('—'),
                        TextEntry::make('approvedBy.name')->label('Approved by')->placeholder('Not approved'),
                        TextEntry::make('approved_at')->label('Approved at')->dateTime('d M Y, h:i A')->placeholder('—'),
                        TextEntry::make('paidBy.name')->label('Marked paid by')->placeholder('Not paid'),
                        TextEntry::make('paid_at')->label('Paid at')->dateTime('d M Y, h:i A')->placeholder('—'),
                    ])
                    ->columns(['default' => 1, 'md' => 2, 'xl' => 3])
                    ->collapsed(),
            ]);
    }

    private static function money(string $name, string $label): TextEntry
    {
        return TextEntry::make($name)
            ->label($label)
            ->formatStateUsing(fn ($state, $record): string => $record->currency.' '.number_format(((int) $state) / 100, 2));
    }
}
