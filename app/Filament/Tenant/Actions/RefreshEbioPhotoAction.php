<?php

namespace App\Filament\Tenant\Actions;

use App\Models\User;
use App\Services\Devices\DeviceIntegration;
use App\Services\Devices\EmployeeBiometrics;
use Filament\Actions\Action;
use Filament\Notifications\Notification;

final class RefreshEbioPhotoAction
{
    public static function make(): Action
    {
        return Action::make('refreshEbioPhoto')->label('Refresh photo')->icon('heroicon-o-photo')
            ->authorize(fn (): bool => DeviceIntegration::usesEbio())
            ->visible(fn (): bool => DeviceIntegration::usesEbio())
            ->action(function (User $record): void {
                try {
                    $saved = app(EmployeeBiometrics::class)->refreshEbioPhoto($record);
                    Notification::make()->title($saved ? 'Employee photo refreshed' : 'No employee photo returned')
                        ->body($saved ? 'The photo is saved privately and available on the employee profile.' : 'The existing saved photo has been kept.')
                        ->status($saved ? 'success' : 'warning')->send();
                } catch (\RuntimeException $exception) {
                    Notification::make()->title('Photo could not be refreshed')
                        ->body('Check eBioServer API access and that this employee has a saved JPEG photo. The existing photo has been kept.')
                        ->danger()->send();
                }
            });
    }
}
