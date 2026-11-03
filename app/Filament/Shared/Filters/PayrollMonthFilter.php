<?php

namespace App\Filament\Shared\Filters;

use App\Services\Support\TenantTimezone;
use Carbon\Carbon;
use Closure;
use Filament\Tables\Filters\SelectFilter;
use Illuminate\Database\Eloquent\Builder;

final class PayrollMonthFilter
{
    public static function make(Closure $slips): SelectFilter
    {
        return SelectFilter::make('payroll_month')->label('Payroll month')
            ->default(fn () => now(TenantTimezone::get())->format('Y-m'))
            ->selectablePlaceholder(false)
            ->options(function () use ($slips): array {
                $current = now(TenantTimezone::get());
                $months = $slips()->select('period_start')->distinct()->orderByDesc('period_start')->pluck('period_start');
                $options = [$current->format('Y-m') => 'This month ('.$current->format('M Y').')', 'all' => 'All months'];
                foreach ($months as $period) {
                    $month = Carbon::parse($period);
                    $options[$month->format('Y-m')] ??= $month->format('F Y');
                }

                return $options;
            })
            ->query(function (Builder $query, array $data): Builder {
                $value = $data['value'] ?? 'all';
                if ($value === 'all' || $value === null || $value === '') {
                    return $query;
                }
                if (! is_string($value) || ! preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', $value)) {
                    return $query->whereRaw('1 = 0');
                }

                return $query->whereDate('period_start', $value.'-01');
            });
    }
}
