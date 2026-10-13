<?php
namespace App\Filament\Tenant\Resources\DeviceResource\Actions;

use App\Models\Device;
use App\Services\Attendance\MatrixDeviceService;
use Filament\Actions\Action;
use Filament\Forms\Components\Select;
use Filament\Notifications\Notification;
use Filament\Schemas\Schema;
use Filament\Support\Exceptions\Halt;

class ConfigureInternalReader
{
    public static function make(): Action
    {
        $allowed = fn (Device $record): bool => $record->vendor === 'matrix' && auth()->check()
            && ((int) auth()->user()->privilege === 14 || auth()->user()->role === 'admin');
        return Action::make('configureInternalReader')
            ->label('Configure Internal Reader')
            ->icon('heroicon-o-identification')->color('info')
            ->visible($allowed)->authorize($allowed)
            ->modalHeading('Configure internal card reader')
            ->modalDescription('Match the card reader type detected in the device’s Basic Profile. This changes the card reader type only.')
            ->modalSubmitActionLabel('Save reader type')
            ->mountUsing(function (Schema $schema, Device $record): void {
                $result = app(MatrixDeviceService::class)->getReaderConfig($record);
                $type = $result['data']['reader1'] ?? '';
                if (!$result['success'] || !preg_match('/^[0-5]$/', (string) $type)) {
                    Notification::make()->title('Could not read internal reader settings')
                        ->body($result['success'] ? 'The device did not return a supported internal reader type.' : ($result['message'] ?? 'The device could not be reached.'))
                        ->danger()->persistent()->send();
                    throw new Halt;
                }
                $schema->fill(['reader1'=>(string) $type]);
            })
            ->schema([
                Select::make('reader1')->label('Internal card reader')->options([
                    '0'=>'None (disabled)', '1'=>'EM Prox', '2'=>'HID Prox',
                    '3'=>'MiFare', '4'=>'HID iCLASS-U', '5'=>'HID iCLASS-W',
                ])->required()->in(['0','1','2','3','4','5'])
                    ->helperText('Choose the detected reader type. This cannot enable hardware that the device does not detect.'),
            ])
            ->action(function (Device $record, array $data): void {
                $result = app(MatrixDeviceService::class)->setInternalCardReader($record, (int) $data['reader1']);
                if (!$result['success']) {
                    Notification::make()->title(!empty($result['outcome_unknown']) ? 'Reader update outcome unknown' : 'Reader update failed')
                        ->body($result['message'])->danger()->persistent()->send();
                    throw new Halt;
                }
                Notification::make()->title('Internal reader type saved')
                    ->body('The device confirmed the new type. Refresh its Basic Profile to check the detected reader and active status.')
                    ->success()->send();
            });
    }
}
