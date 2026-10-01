<?php

namespace App\Services\Payroll;

use Barryvdh\DomPDF\Facade\Pdf;
use Filament\Notifications\Notification;
use Illuminate\Database\Eloquent\Builder;
use Symfony\Component\HttpFoundation\StreamedResponse;

class SalarySlipExportService
{
    public function pdf(Builder $query, string $organisation): ?StreamedResponse
    {
        $slips = (clone $query)->with(['user.branch', 'user.department', 'user.taskGroups'])->limit(251)->get();
        if ($slips->isEmpty() || $slips->count() > 250) {
            Notification::make()->title('Download unavailable')->body($slips->isEmpty() ? 'No salary slips match the applied filters.' : 'Filter to 250 or fewer salary slips per PDF download.')->warning()->send();

            return null;
        }
        $totals = $slips->groupBy('currency')->map(fn ($rows): array => [
            'count' => $rows->count(), 'gross' => $rows->sum('gross_pay_minor'),
            'deductions' => $rows->sum('total_deduction_minor'), 'net' => $rows->sum('net_pay_minor'),
        ]);
        $pdf = Pdf::loadView('filament.tenant.pages.pdf.salary-slips', compact('slips', 'totals', 'organisation'))->setPaper('a4');

        return response()->streamDownload(fn () => print($pdf->output()), 'salary-slips-'.now()->format('Y-m-d').'.pdf', ['Content-Type' => 'application/pdf']);
    }

    public function csv(Builder $query): ?StreamedResponse
    {
        $count = (clone $query)->reorder()->count();
        if ($count === 0 || $count > 10000) {
            Notification::make()->title('Download unavailable')->body($count === 0 ? 'No salary slips match the applied filters.' : 'Filter to 10,000 or fewer salary slips per CSV download.')->warning()->send();

            return null;
        }
        $query = (clone $query)->with(['user.branch', 'user.department', 'user.taskGroups'])
            ->orderBy($query->getModel()->getQualifiedKeyName());

        return response()->streamDownload(function () use ($query): void {
            $stream = fopen('php://output', 'w');
            fputcsv($stream, ['Payroll month', 'Employee PIN', 'Employee', 'Branch', 'Department', 'Task groups', 'Status', 'Currency', 'Gross pay', 'Total deduction', 'Net pay'], ',', '"', '');
            $totals = [];
            foreach ($query->lazy(250) as $slip) {
                $row = [$slip->period_start->format('Y-m'), $slip->user?->pin, $slip->user?->name, $slip->user?->branch?->name, $slip->user?->department?->name, $slip->user?->taskGroups->pluck('name')->implode(', '), $slip->status, $slip->currency,
                    number_format($slip->gross_pay_minor / 100, 2, '.', ''), number_format($slip->total_deduction_minor / 100, 2, '.', ''), number_format($slip->net_pay_minor / 100, 2, '.', '')];
                // Imported employee names and PINs must not execute spreadsheet formulas.
                $row = array_map(fn ($value) => preg_match('/^[\s]*[=+\-@]/', (string) $value) ? "'".$value : $value, $row);
                fputcsv($stream, $row, ',', '"', '');
                foreach (['gross_pay_minor', 'total_deduction_minor', 'net_pay_minor'] as $field) {
                    $totals[$slip->currency][$field] = ($totals[$slip->currency][$field] ?? 0) + $slip->{$field};
                }
            }
            foreach ($totals as $currency => $amounts) {
                fputcsv($stream, ['', '', 'TOTAL', '', '', '', '', $currency, ...array_map(fn ($minor) => number_format($minor / 100, 2, '.', ''), array_values($amounts))], ',', '"', '');
            }
            fclose($stream);
        }, 'payroll-'.now()->format('Y-m-d').'.csv', ['Content-Type' => 'text/csv; charset=UTF-8']);
    }
}
