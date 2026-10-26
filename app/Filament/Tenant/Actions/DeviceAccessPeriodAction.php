<?php

namespace App\Filament\Tenant\Actions;

use App\Models\Device;
use App\Models\GatewayDeviceBinding;
use App\Models\User;
use App\Services\Devices\DeviceAccessValidity;
use App\Services\Devices\DeviceIntegration;
use App\Services\Devices\DeviceProfilePolicy;
use Filament\Actions\Action;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Select;
use Filament\Notifications\Notification;

final class DeviceAccessPeriodAction
{
    private static function devices(): array
    {
        $key = 'device-access-period:'.tenant('id');
        if (! request()->attributes->has($key)) {
            $bindings = GatewayDeviceBinding::where('organisation_id', tenant('id'))->where('enabled', true)
                ->whereNotNull('synced_at')->whereJsonContains('capabilities', 'set_user_validity')->get()->keyBy('device_id');
            $devices = Device::whereIn('id', $bindings->keys())->orderBy('name')->get()
                ->filter(fn (Device $device): bool => $bindings[$device->id]->serial === $device->serial_number && DeviceProfilePolicy::supportsValidity($device))
                ->pluck('name', 'id')->all();
            request()->attributes->set($key, $devices);
        }

        return request()->attributes->get($key);
    }

    public static function make(): Action
    {
        return Action::make('deviceAccessPeriod')
            ->label('Access period')->icon('heroicon-o-calendar-days')->modalWidth('lg')
            ->authorize(fn (): bool => tenant()?->device_integration === DeviceIntegration::GATEWAY)
            ->visible(fn (): bool => config('device_gateway.user_validity_enabled', false) && tenant()?->device_integration === DeviceIntegration::GATEWAY && self::devices() !== [])
            ->modalDescription('Set device-local access dates for an employee already registered on a compatible device. Payroll and administrator privileges stay unchanged.')
            ->schema([
                Select::make('device_id')->label('Device')->required()->searchable()
                    ->options(fn (): array => self::devices())
                    ->helperText('Only devices in the selected compatibility profile are listed.'),
                DatePicker::make('from')->label('Valid from')->native(true)
                    ->required(),
                DatePicker::make('until')->label('End date')->native(true)->afterOrEqual('from')
                    ->required()
                    ->helperText('Calendar dates in the selected device’s timezone. Daily time slots are separate.'),
            ])
            ->modalSubmitActionLabel('Apply access period')
            ->action(function (User $record, array $data): void {
                $input = ['mode' => 'period', 'from' => $data['from'] ?? null, 'until' => $data['until'] ?? null];
                app(DeviceAccessValidity::class)->apply($record, Device::findOrFail($data['device_id']), $input);
                Notification::make()->title('Access update queued')->body('Track acknowledgement in Device Commands.')->success()->send();
            });
    }
}
