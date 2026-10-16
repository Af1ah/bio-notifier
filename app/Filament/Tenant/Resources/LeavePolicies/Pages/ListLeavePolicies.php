<?php

namespace App\Filament\Tenant\Resources\LeavePolicies\Pages;

use App\Filament\Tenant\LeaveActionErrors;
use App\Filament\Tenant\Resources\LeavePolicies\LeavePolicyResource;
use App\Filament\Tenant\Resources\LeaveTypes\LeaveTypeResource;
use App\Services\Leave\LeavePolicyService;
use App\Services\Leave\LeaveSettingsService;
use Carbon\Carbon;
use Filament\Actions\Action;
use Filament\Actions\CreateAction;
use Filament\Forms\Components\Checkbox;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ListRecords;
use Filament\Schemas\Schema;

class ListLeavePolicies extends ListRecords
{
    protected static string $resource = LeavePolicyResource::class;

    public function getTitle(): string
    {
        return 'Leave settings';
    }

    protected function getHeaderActions(): array
    {
        return [CreateAction::make()->label('Add policy'), Action::make('categories')->label('Leave categories')->url(LeaveTypeResource::getUrl()), Action::make('calendarSettings')->label('Leave calendar settings')->fillForm(fn () => ['timezone' => LeaveSettingsService::timezone(), 'expiry_warning_days' => LeaveSettingsService::expiryWarningDays()])->schema([
            Select::make('timezone')->label('Select time zone')->options(array_combine(timezone_identifiers_list(), timezone_identifiers_list()))->searchable()->required(),
            TextInput::make('expiry_warning_days')->label('Expiry warning days')->numeric()->integer()->minValue(0)->maxValue(365)->required(),
        ])->action(fn (array $data, Schema $schema) => LeaveActionErrors::run($schema, fn () => app(LeaveSettingsService::class)->save($data, auth()->user()), 'timezone')), Action::make('starterPolicies')->label('Add starter policies')->schema([
            DatePicker::make('effective_from')->required()->default(fn () => today(LeaveSettingsService::timezone())),
            Checkbox::make('confirm')->label('I will review these example entitlements for this company before using them.')->accepted()->required(),
        ])->action(function (array $data, Schema $schema) {
            LeaveActionErrors::run($schema, fn () => app(LeavePolicyService::class)->installDefaults(Carbon::parse($data['effective_from']), auth()->user()), 'effective_from');
            Notification::make()->title('Editable starter policies added')->success()->send();
        })];
    }
}
