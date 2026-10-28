<?php

namespace App\Filament\Tenant\Actions;

use App\Models\Device;
use App\Models\DeviceRosterEntry;
use App\Services\Devices\DeviceAccessValidity;
use App\Services\Devices\DeviceCommandService;
use App\Services\Devices\DeviceUserAccess;
use Filament\Actions\Action;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Hidden;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Illuminate\Support\Str;

final class DeviceAccessPeriodAction
{
    public static function make(Device $device): Action
    {
        $available = function () use ($device): bool {
            $key = 'device-expiration-available:'.tenant('id').':'.$device->id;
            if (! request()->attributes->has($key)) {
                request()->attributes->set($key, app(DeviceCommandService::class)->available($device, 'set_user_validity')
                    && app(DeviceCommandService::class)->available($device, 'query_users'));
            }

            return request()->attributes->get($key);
        };

        return Action::make('deviceAccessPeriod')
            ->label('Expiration rule')->icon('heroicon-o-calendar-days')->modalWidth('lg')
            ->authorize(fn (): bool => (bool) auth('web')->user()?->has_login && $available())
            ->visible($available)
            ->modalDescription('Choose no expiration, dates, an entry limit, or both. Daily time periods and employee joining/end dates remain separate. Changing the rule replaces its previous limits.')
            ->fillForm(function (DeviceRosterEntry $record): array {
                $access = $record->access_settings ?? [];
                $date = fn ($key) => preg_match('/^\d{4}-\d{2}-\d{2}$/', $value = DeviceUserAccess::date($access[$key] ?? null)) ? $value : null;

                return ['request_id' => (string) Str::uuid(), 'mode' => ['0' => 'none', '1' => 'dates', '2' => 'entries', '3' => 'both'][$access['Expires'] ?? ''] ?? null,
                    'from' => $date('StartDatetime'), 'until' => $date('EndDatetime')];
            })
            ->schema([
                Hidden::make('request_id')->required()->rules(['uuid']),
                Select::make('mode')->label('Expiration rule')->options(DeviceAccessValidity::MODES)->required()->live(),
                DatePicker::make('from')->label('Start date')->native(true)->required()
                    ->visible(fn ($get): bool => in_array($get('mode'), ['dates', 'both'], true)),
                DatePicker::make('until')->label('End date')->native(true)->afterOrEqual('from')->required()
                    ->visible(fn ($get): bool => in_array($get('mode'), ['dates', 'both'], true)),
                TextInput::make('entries')->label('Entry limit')->integer()->minValue(1)->maxValue(65535)->required()
                    ->helperText('Sets a new allowance once. Reapplying a limit can replenish remaining entries.')
                    ->visible(fn ($get): bool => in_array($get('mode'), ['entries', 'both'], true)),
            ])
            ->modalSubmitActionLabel('Save and sync')
            ->action(function (DeviceRosterEntry $record, array $data) use ($device): void {
                $input = ['mode' => $data['mode']];
                if (in_array($input['mode'], ['dates', 'both'], true)) {
                    $input += ['from' => $data['from'] ?? null, 'until' => $data['until'] ?? null];
                }
                if (in_array($input['mode'], ['entries', 'both'], true)) {
                    $input['entries'] = (string) ($data['entries'] ?? '');
                }
                app(DeviceAccessValidity::class)->applyToDeviceUser($record, $device, $input, $data['request_id']);
                Notification::make()->title('Expiration rule queued')->body('Track acknowledgement and readback in Device Commands. Confirm omitted mode/count values on the terminal.')->success()->send();
            });
    }
}
