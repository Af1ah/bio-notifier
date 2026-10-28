<?php

namespace App\Filament\Tenant\Actions;

use App\Models\Device;
use App\Models\DeviceRosterEntry;
use App\Services\Devices\DeviceAccessValidity;
use App\Services\Devices\DeviceCommandService;
use Filament\Actions\Action;
use Filament\Forms\Components\DatePicker;
use Filament\Notifications\Notification;

final class DeviceAccessPeriodAction
{
    public static function make(Device $device): Action
    {
        return Action::make('deviceAccessPeriod')
            ->label('Edit expiration dates')->icon('heroicon-o-calendar-days')->modalWidth('lg')
            ->authorize(fn (): bool => (bool) auth('web')->user()?->has_login && app(DeviceCommandService::class)->available($device, 'set_user_validity'))
            ->visible(fn (): bool => app(DeviceCommandService::class)->available($device, 'set_user_validity'))
            ->modalDescription('Set calendar dates in this device’s timezone. Entry limits and daily periods are separate settings. Confirm the result on the terminal.')
            ->schema([
                DatePicker::make('from')->label('Start date')->native(true)->required(),
                DatePicker::make('until')->label('End date')->native(true)->afterOrEqual('from')->required(),
            ])
            ->modalSubmitActionLabel('Queue update')
            ->action(function (DeviceRosterEntry $record, array $data) use ($device): void {
                app(DeviceAccessValidity::class)->applyToDeviceUser($record, $device, [
                    'mode' => 'period', 'from' => $data['from'] ?? null, 'until' => $data['until'] ?? null,
                ]);
                Notification::make()->title('Expiration update queued')->body('Track the result in Device actions, then read device users again.')->success()->send();
            });
    }
}
