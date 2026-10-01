<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <title>Salary slips</title>
    <style>
        body { font-family: DejaVu Sans, sans-serif; font-size: 11px; color: #222; }
        h1 { font-size: 20px; margin: 0 0 8px; }
        h2 { font-size: 15px; margin: 20px 0 8px; }
        p { margin: 4px 0; }
        table { width: 100%; border-collapse: collapse; margin: 12px 0; }
        th, td { padding: 8px; border-bottom: 1px solid #ddd; text-align: left; }
        th { background: #f3f4f6; }
        .money { text-align: right; white-space: nowrap; }
        .slip { page-break-before: always; }
        .net { font-weight: bold; font-size: 14px; }
        .muted { color: #666; }
    </style>
</head>
<body>
    <h1>{{ $organisation }}</h1>
    <h2>Payroll summary</h2>
    <p>{{ $slips->count() }} salary slips · Generated {{ now()->format('d M Y') }}</p>
    <p class="muted">Includes only the applied list filters. Draft and reviewed slips are not final payroll.</p>
    <table>
        <thead><tr><th>Currency</th><th>Slips</th><th class="money">Gross pay</th><th class="money">Deductions</th><th class="money">Net pay</th></tr></thead>
        <tbody>
        @foreach ($totals as $currency => $total)
            <tr><td>{{ $currency }}</td><td>{{ $total['count'] }}</td><td class="money">{{ number_format($total['gross'] / 100, 2) }}</td><td class="money">{{ number_format($total['deductions'] / 100, 2) }}</td><td class="money">{{ number_format($total['net'] / 100, 2) }}</td></tr>
        @endforeach
        </tbody>
    </table>
    @foreach ($slips as $slip)
        <section class="slip">
            <h1>{{ $organisation }}</h1>
            <h2>Salary slip · {{ $slip->period_start->format('F Y') }}</h2>
            <p><strong>{{ $slip->user?->name }}</strong> · PIN {{ $slip->user?->pin }}</p>
            <p>Branch: {{ $slip->user?->branch?->name ?? 'Not assigned' }} · Department: {{ $slip->user?->department?->name ?? 'Not assigned' }}</p>
            <p>Task groups: {{ $slip->user?->taskGroups->pluck('name')->implode(', ') ?: 'Not assigned' }}</p>
            <p>Status: {{ ucfirst($slip->status) }} · Calculated: {{ $slip->calculated_at?->format('d M Y H:i') }}</p>
            @if ($slip->calculation_snapshot['needs_recalculation'] ?? false)
                <p><strong>Attendance has changed. This slip requires payroll review.</strong></p>
            @endif
            <table>
                <thead><tr><th>Earnings</th><th class="money">Amount ({{ $slip->currency }})</th></tr></thead>
                <tbody>
                @foreach (['basic_salary_minor' => 'Base pay', 'housing_allowance_minor' => 'Housing allowance', 'transport_allowance_minor' => 'Transport allowance', 'other_allowance_minor' => 'Other allowance', 'overtime_pay_minor' => 'Overtime pay', 'gross_pay_minor' => 'Gross pay'] as $field => $label)
                    <tr><td>{{ $label }}</td><td class="money">{{ number_format($slip->{$field} / 100, 2) }}</td></tr>
                @endforeach
                </tbody>
            </table>
            <table>
                <thead><tr><th>Deductions</th><th class="money">Amount ({{ $slip->currency }})</th></tr></thead>
                <tbody>
                @foreach (['attendance_deduction_minor' => 'Attendance deduction', 'leave_deduction_minor' => 'Leave deduction', 'fixed_deduction_minor' => 'Fixed deduction', 'total_deduction_minor' => 'Total deduction'] as $field => $label)
                    <tr><td>{{ $label }}</td><td class="money">{{ number_format($slip->{$field} / 100, 2) }}</td></tr>
                @endforeach
                    <tr class="net"><td>Net pay</td><td class="money">{{ $slip->formattedMoney('net_pay_minor') }}</td></tr>
                </tbody>
            </table>
            <p>Scheduled days: {{ $slip->scheduled_days }} · Full days: {{ $slip->full_days }} · Half days: {{ $slip->half_days }} · Absent days: {{ $slip->absent_days + (($slip->calculation_snapshot['leave_other_half_absent_days'] ?? 0) / 2) }}</p>
            <p>Paid leave: {{ $slip->paid_leave_half_units / 2 }} days · Unpaid leave: {{ $slip->unpaid_leave_half_units / 2 }} days · Approved overtime: {{ $slip->approved_overtime_minutes }} minutes</p>
        </section>
    @endforeach
</body>
</html>
