<x-filament-panels::page>
    @if ($latestPaid)
        <x-portal-ui.card variant="hero" class="portal-stack">
            <p class="portal-kicker">{{ __('portal-ui.latest_paid') }} · {{ $latestPaid->period_start->translatedFormat('F Y') }}</p>
            <p class="portal-clock">{{ $latestPaid->formattedMoney('net_pay_minor') }}</p>
            <dl class="portal-metrics portal-metrics--ruled">
                <x-portal-ui.metric :label="__('portal-ui.gross_pay')" :value="$latestPaid->formattedMoney('gross_pay_minor')" />
                <x-portal-ui.metric :label="__('portal-ui.deductions')" :value="$latestPaid->formattedMoney('total_deduction_minor')" />
            </dl>
        </x-portal-ui.card>
    @endif
    <div class="portal-data-grid portal-payslip-list">{{ $this->table }}</div>
</x-filament-panels::page>
