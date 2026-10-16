<?php

namespace App\Filament\Tenant\Resources\LeavePolicies\Pages;

use App\Filament\Tenant\LeaveActionErrors;
use App\Filament\Tenant\Resources\LeavePolicies\LeavePolicyResource;
use App\Services\Leave\LeavePolicyService;
use App\Services\Leave\LeaveSettingsService;
use Carbon\Carbon;
use Filament\Resources\Pages\EditRecord;
use Illuminate\Database\Eloquent\Model;

class EditLeavePolicy extends EditRecord
{
    protected static string $resource = LeavePolicyResource::class;

    protected function mutateFormDataBeforeFill(array $data): array
    {
        $revision = $this->record->revisions()->orderByDesc('effective_from')->first();
        $data['rules'] = array_replace(LeavePolicyService::defaults(), $revision?->rules ?? []);
        $data['rules']['expiry_method'] = ! empty($data['rules']['reset_dates']) ? 'resets' : (empty($data['rules']['fixed_expiry_month']) ? 'relative' : 'fixed');
        $next = today(LeaveSettingsService::timezone())->max($revision?->effective_from ?? today(LeaveSettingsService::timezone()));
        $annualNext = ! empty($data['rules']['leave_period_to']) ? Carbon::parse($data['rules']['leave_period_to'])->addDay() : $next->copy()->addYear()->startOfYear();
        while ($annualNext->lte($next)) {
            $annualNext->addYearNoOverflow();
        }
        $next = match ($revision?->rules['mode'] ?? 'monthly') {
            'annual' => $annualNext,
            'monthly' => $next->copy()->addMonthNoOverflow()->startOfMonth(),
            default => $next->copy()->addDay(),
        };
        if (! empty($data['rules']['leave_period_to']) && $next->toDateString() > $data['rules']['leave_period_to']) {
            if (($revision?->rules['mode'] ?? 'monthly') === 'annual') {
                $data['rules']['leave_period_from'] = $next->toDateString();
                $data['rules']['leave_period_to'] = $next->copy()->addYearNoOverflow()->subDay()->toDateString();
            } else {
                $from = Carbon::parse($data['rules']['leave_period_from']);
                $to = Carbon::parse($data['rules']['leave_period_to']);
                while ($to->lt($next)) {
                    $from->addYearNoOverflow();
                    $to->addYearNoOverflow();
                }
                $data['rules']['leave_period_from'] = $from->toDateString();
                $data['rules']['leave_period_to'] = $to->toDateString();
            }
        }
        if (! empty($data['rules']['fixed_expiry_month'])) {
            $cutoff = Carbon::create($next->year, $data['rules']['fixed_expiry_month'], 1)->startOfDay();
            // A legacy February month-end rule must still include leap day after editing.
            if ((int) $data['rules']['fixed_expiry_month'] === 2 && (int) $data['rules']['fixed_expiry_day'] >= 29) {
                while (! $cutoff->isLeapYear()) {
                    $cutoff->addYear();
                }
            }
            $cutoff->day(min((int) $data['rules']['fixed_expiry_day'], $cutoff->daysInMonth));
            $data['rules']['annual_cutoff_date'] = $cutoff->toDateString();
        }
        $data['effective_from'] = $next->toDateString();
        $data['reason'] = '';

        return $data;
    }

    protected function handleRecordUpdate(Model $record, array $data): Model
    {
        return LeaveActionErrors::form(fn () => app(LeavePolicyService::class)->save($record, $data, auth()->user()));
    }
}
