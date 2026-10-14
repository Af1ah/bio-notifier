<?php

namespace App\Filament\Tenant\Resources\LeavePolicies\Pages;

use App\Filament\Tenant\LeaveActionErrors;
use App\Filament\Tenant\Resources\LeavePolicies\LeavePolicyResource;
use App\Services\Leave\LeavePolicyService;
use App\Services\Leave\LeaveSettingsService;
use Filament\Resources\Pages\EditRecord;
use Illuminate\Database\Eloquent\Model;

class EditLeavePolicy extends EditRecord
{
    protected static string $resource = LeavePolicyResource::class;

    protected function mutateFormDataBeforeFill(array $data): array
    {
        $revision = $this->record->revisions()->orderByDesc('effective_from')->first();
        $data['rules'] = array_replace(LeavePolicyService::defaults(), $revision?->rules ?? []);
        $next = today(LeaveSettingsService::timezone())->max($revision?->effective_from ?? today(LeaveSettingsService::timezone()));
        $next = match ($revision?->rules['mode'] ?? 'monthly') {
            'annual' => $next->copy()->addYear()->startOfYear(),
            'monthly' => $next->copy()->addMonthNoOverflow()->startOfMonth(),
            default => $next->copy()->addDay(),
        };
        $data['effective_from'] = $next->toDateString();
        $data['reason'] = '';

        return $data;
    }

    protected function handleRecordUpdate(Model $record, array $data): Model
    {
        return LeaveActionErrors::form(fn () => app(LeavePolicyService::class)->save($record, $data, auth()->user()));
    }
}
