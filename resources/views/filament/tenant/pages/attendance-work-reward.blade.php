<div class="space-y-3">
    <p><strong>{{ $day->user->name }}</strong> · {{ $day->work_date->format('d M Y') }}</p>
    <p>Status: <strong>{{ $state }}</strong></p>
    @if ($state === 'Needs review')
        <p>Attendance changed after approval. Review the award and any leave already using its credit before continuing payroll.</p>
    @endif
    <p>Approved work at award: {{ $award->evidence['approved_minutes'] ?? 0 }} minutes</p>
    @if (in_array($award->reward, ['leave', 'both']))
        <p>Earned paid leave: {{ $award->half_units / 2 }} day(s)</p>
        @php($credit = \App\Models\LeaveCreditLot::find($award->leave_credit_lot_id))
        @if ($credit)
            <p>Available: {{ $credit->available_on->format('d M Y') }} · Expires: {{ $credit->expires_on->format('d M Y') }}</p>
        @endif
    @endif
    @if ($award->payment_minor > 0)
        <p>Work reward payment: {{ $award->evidence['policy_rules']['comp_payment_currency'] ?? '' }} {{ number_format($award->payment_minor / 100, 2) }}</p>
    @endif
    <p>Approval reason: {{ $award->reason }}</p>
    @if ($award->state === 'reversed')
        <p>Reversal reason: {{ $award->evidence['reversal_reason'] ?? 'Recorded in history' }}</p>
    @endif
    <p>See Leave Balances for credit usage and history. Taking this leave is recorded through Employee Leave.</p>
</div>
