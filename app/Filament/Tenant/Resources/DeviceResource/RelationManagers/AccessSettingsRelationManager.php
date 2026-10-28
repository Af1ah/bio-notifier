<?php

namespace App\Filament\Tenant\Resources\DeviceResource\RelationManagers;

use App\Models\DeviceAccessSetting;
use App\Services\Devices\DeviceAccessSettings;
use App\Services\Devices\DeviceCommandService;
use App\Services\Devices\DeviceIntegration;
use App\Services\Devices\DeviceProfilePolicy;
use App\Services\Support\TenantTimezone;
use Filament\Actions\Action;
use Filament\Forms\Components\Checkbox;
use Filament\Forms\Components\Hidden;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\TimePicker;
use Filament\Infolists\Components\TextEntry;
use Filament\Notifications\Notification;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Schemas\Components\Grid;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;

abstract class AccessSettingsRelationManager extends RelationManager
{
    protected static string $accessKind;

    public static function canViewForRecord(Model $ownerRecord, string $pageClass): bool
    {
        return tenant()?->device_integration === DeviceIntegration::GATEWAY && DeviceProfilePolicy::supportsValidity($ownerRecord);
    }

    private function editable(): bool
    {
        return (bool) auth('web')->user()?->has_login && self::canViewForRecord($this->getOwnerRecord(), static::class);
    }

    private function fields(bool $creating): array
    {
        $fields = [TextInput::make('name')->label('Name')->required()->maxLength(100), Hidden::make('revision')];
        if ($creating) {
            $fields[] = TextInput::make('number')->label(static::$accessKind === 'group' ? 'Device group number' : 'Device period number')
                ->integer()->minValue(1)->maxValue(65535)->required()
                ->helperText('Use a number supported by this terminal. Check that the slot is unused before adding it.');
        }
        if (static::$accessKind === 'group') {
            $fields[] = Select::make('periods')->label('Daily periods')->multiple()->minItems(1)->maxItems(3)->required()
                ->options(fn () => DeviceAccessSetting::where('device_id', $this->getOwnerRecord()->id)->where('kind', 'period')->orderBy('number')->limit(1000)->get()
                    ->mapWithKeys(fn ($period) => [(string) $period->number => $period->number.' · '.$period->name])->all())
                ->helperText('Apply each daily period before applying the group. Existing verification and holiday settings are preserved.');
        } else {
            $hours = [];
            foreach (DeviceAccessSettings::DAYS as $day => $label) {
                $hours[] = TimePicker::make($day.'Start')->label($label.' start')->seconds(false)->format('H:i')->native()->required();
                $hours[] = TimePicker::make($day.'End')->label($label.' end')->seconds(false)->format('H:i')->native()->required();
            }
            $fields[] = Grid::make(2)->schema($hours);
        }
        if ($creating) {
            $fields[] = Checkbox::make('slots_confirmed')->label('I checked that this number is unused on this terminal.')->accepted()->required();
        }

        return $fields;
    }

    private function saveData(array $data, ?DeviceAccessSetting $record = null): void
    {
        $settings = ['number' => (string) ($record?->number ?? $data['number'])];
        if (static::$accessKind === 'group') {
            $preserved = $record?->reported ?? $record?->desired ?? [];
            $periods = array_map('strval', $data['periods'] ?? []);
            $settings += ['Verify' => $preserved['Verify'] ?? '0', 'ValidHoliday' => $preserved['ValidHoliday'] ?? '0',
                'TZ' => '1;'.implode(';', array_pad($periods, 3, '0'))];
        } else {
            foreach (DeviceAccessSettings::DAYS as $day => $label) {
                foreach (['Start', 'End'] as $end) {
                    $settings[$day.$end] = str_replace(':', '', $data[$day.$end] ?? '');
                }
            }
        }
        app(DeviceAccessSettings::class)->save($this->getOwnerRecord(), static::$accessKind,
            ['name' => $data['name'], 'settings' => $settings, 'revision' => $data['revision'] ?? null, 'slots_confirmed' => $data['slots_confirmed'] ?? false], $record);
        Notification::make()->title('Draft saved')->body('Choose Apply to device when the settings are ready.')->success()->send();
    }

    public function table(Table $table): Table
    {
        return $table->columns([
            TextColumn::make('number')->label('Device number')->sortable(),
            TextColumn::make('name')->searchable(),
            TextColumn::make('state')->label('Status')->getStateUsing(function (DeviceAccessSetting $record): string {
                if ($record->submitted_revision === $record->revision && $record->command_id) {
                    return match ($record->command?->status) {
                        'pending' => 'Queued', 'sent' => 'Waiting for response', 'acknowledged' => $record->reported == $record->desired ? 'Readback matches' : 'Acknowledged · readback needed',
                        'failed' => 'Failed', 'expired' => 'Expired', 'cancelled' => 'Cancelled', default => 'Needs review',
                    };
                }

                return $record->reported && $record->desired == $record->reported ? 'Read from device' : 'Draft';
            }),
            TextColumn::make('reported_at')->label('Last read')->dateTime(timezone: fn () => TenantTimezone::get())->placeholder('Not read yet'),
        ])->defaultSort('number')->modifyQueryUsing(fn ($query) => $query->with('command'))
            ->description('Save a draft, then apply it on demand. Shared changes affect everyone using this group or period. Missing records do not prove that device slots are unused.')
            ->emptyStateHeading('No '.(static::$accessKind === 'group' ? 'access groups' : 'daily periods').' received')
            ->emptyStateDescription('Read access settings from the device. Firmware that does not return usable settings cannot provide existing-group editing.')
            ->headerActions([
                Action::make('readAccessSettings')->label('Read access settings')->icon('heroicon-o-arrow-path')
                    ->authorize(fn (): bool => $this->editable() && app(DeviceCommandService::class)->available($this->getOwnerRecord(), 'query_access_groups') && app(DeviceCommandService::class)->available($this->getOwnerRecord(), 'query_access_periods'))
                    ->action(function (): void {
                        foreach (['query_access_periods', 'query_access_groups'] as $operation) {
                            app(DeviceCommandService::class)->request($this->getOwnerRecord(), $operation);
                        }
                        Notification::make()->title('Access settings read queued')->body('Only returned records will appear. Acknowledgement alone does not provide group settings.')->success()->send();
                    }),
                Action::make('addAccessSetting')->label(static::$accessKind === 'group' ? 'Add group' : 'Add period')
                    ->authorize(fn (): bool => $this->editable())->schema($this->fields(true))->modalSubmitActionLabel('Save draft')
                    ->action(fn (array $data) => $this->saveData($data)),
            ])->recordActions([
                Action::make('viewAccessSetting')->label('Details')->modalSubmitAction(false)->modalCancelActionLabel('Close')
                    ->schema([
                        TextEntry::make('desired_summary')->label('Draft settings')->getStateUsing(fn (DeviceAccessSetting $record) => self::summary($record->desired)),
                        TextEntry::make('reported_summary')->label('Last device readback')->getStateUsing(fn (DeviceAccessSetting $record) => $record->reported ? self::summary($record->reported) : 'Not reported'),
                    ]),
                Action::make('editAccessSetting')->label('Edit')->authorize(fn (): bool => $this->editable())
                    ->schema($this->fields(false))->fillForm(function (DeviceAccessSetting $record): array {
                        $data = ['name' => $record->name, 'revision' => $record->revision];
                        if ($record->kind === 'group') {
                            $data['periods'] = array_values(array_filter(array_slice(explode(';', $record->desired['TZ']), 1), fn ($slot) => $slot !== '0'));
                        } else {
                            foreach ($record->desired as $key => $value) {
                                if ($key !== 'number') {
                                    $data[$key] = substr($value, 0, 2).':'.substr($value, 2, 2);
                                }
                            }
                        }

                        return $data;
                    })->modalSubmitActionLabel('Save draft')->action(fn (DeviceAccessSetting $record, array $data) => $this->saveData($data, $record)),
                Action::make('applyAccessSetting')->label('Apply to device')->authorize(fn (): bool => $this->editable())
                    ->disabled(fn (): bool => ! app(DeviceCommandService::class)->available($this->getOwnerRecord(), 'set_access_'.static::$accessKind))
                    ->tooltip(fn (): ?string => config('device_gateway.access_groups_enabled') ? null : 'Device writes await firmware qualification.')
                    ->modalDescription('This changes a shared device setting. All users and groups referencing it may be affected. Check the draft and current readback before applying.')
                    ->schema([Hidden::make('revision'), Checkbox::make('confirmed')->label('I reviewed the shared access change.')->required()->accepted()])
                    ->fillForm(fn (DeviceAccessSetting $record) => ['revision' => $record->revision])
                    ->action(function (DeviceAccessSetting $record, array $data): void {
                        validator($data, ['confirmed' => ['required', 'accepted']])->validate();
                        app(DeviceAccessSettings::class)->apply($this->getOwnerRecord(), $record, (int) $data['revision']);
                        Notification::make()->title('Access update queued')->body('Check Device actions, then read the settings again. Verify access on the terminal.')->success()->send();
                    }),
            ])->toolbarActions([]);
    }

    private static function summary(array $settings): string
    {
        if (isset($settings['TZ'])) {
            return 'Periods: '.implode(', ', array_filter(array_slice(explode(';', $settings['TZ']), 1), fn ($slot) => $slot !== '0'))
                .' · Verification mode: '.$settings['Verify'].' · Holiday access: '.($settings['ValidHoliday'] === '1' ? 'Yes' : 'No');
        }
        $days = [];
        foreach (DeviceAccessSettings::DAYS as $day => $label) {
            $format = fn ($value) => substr($value, 0, 2).':'.substr($value, 2, 2);
            $days[] = $label.': '.$format($settings[$day.'Start']).'–'.$format($settings[$day.'End']);
        }

        return implode('; ', $days);
    }
}
