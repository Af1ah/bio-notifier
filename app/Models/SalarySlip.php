<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class SalarySlip extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'period_start' => 'date',
            'period_end' => 'date',
            'calculation_snapshot' => 'array',
            'calculated_at' => 'datetime',
            'reviewed_at' => 'datetime',
            'approved_at' => 'datetime',
            'paid_at' => 'datetime',
        ];
    }

    public function getOvertimeBreakdownDisplayAttribute(): string
    {
        return collect($this->calculation_snapshot['overtime_breakdown'] ?? [])->map(fn ($row) => $row['date'].' · '.($row['shift'] ?? 'Employee rate').' · '.$row['minutes'].' min · '.$row['currency'].' '.number_format($row['hourly_rate_minor'] / 100, 2).'/hour · '.$row['currency'].' '.number_format(($row['amount_minor'] ?? 0) / 100, 2))->implode('; ');
    }

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function reviewedBy()
    {
        return $this->belongsTo(User::class, 'reviewed_by_user_id');
    }

    public function approvedBy()
    {
        return $this->belongsTo(User::class, 'approved_by_user_id');
    }

    public function paidBy()
    {
        return $this->belongsTo(User::class, 'paid_by_user_id');
    }

    public function getTotalWorkedHoursAttribute(): ?string
    {
        if (! isset($this->calculation_snapshot['approved_worked_minutes'])) {
            return null;
        }
        $minutes = (int) $this->calculation_snapshot['approved_worked_minutes'];

        return intdiv($minutes, 60).'h '.str_pad((string) ($minutes % 60), 2, '0', STR_PAD_LEFT).'m';
    }

    public function getPerDaySalaryDisplayAttribute(): string
    {
        $snapshot = $this->calculation_snapshot ?? [];
        $basis = $snapshot['pay_basis'] ?? 'monthly';
        if ($basis === 'hourly') {
            return 'Not applicable (hourly pay)';
        }
        $rate = $snapshot[$basis === 'daily' ? 'base_rate_minor' : 'calendar_day_rate_minor'] ?? null;

        return $rate === null ? 'Not recorded' : $this->currency.' '.number_format(((int) $rate) / 100, 2);
    }

    public function formattedMoney(string $attribute): string
    {
        return $this->currency.' '.number_format(((int) $this->{$attribute}) / 100, 2);
    }
}
