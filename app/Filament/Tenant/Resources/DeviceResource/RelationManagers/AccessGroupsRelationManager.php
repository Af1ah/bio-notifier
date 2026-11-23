<?php

namespace App\Filament\Tenant\Resources\DeviceResource\RelationManagers;

use App\Models\DeviceAccessSetting;
use App\Services\Devices\DeviceAccessRules;
use App\Services\Devices\DeviceAccessSettings;
use App\Services\Devices\DeviceCommandService;
use Filament\Actions\Action;
use Filament\Forms\Components\CheckboxList;
use Filament\Forms\Components\Hidden;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\TimePicker;
use Filament\Infolists\Components\TextEntry;
use Filament\Notifications\Notification;
use Filament\Schemas\Components\Grid;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

class AccessGroupsRelationManager extends AccessSettingsRelationManager
{
    protected static string $relationship = 'accessGroups';
    protected static ?string $title = 'Access rules';
    protected static string $accessKind = 'group';

    private function canManageRules(): bool
    {
        return (bool) auth('web')->user()?->has_login && (bool) auth('web')->user()?->canManageDevices()
            && self::canViewForRecord($this->getOwnerRecord(), static::class);
    }

    private function canSendRules(): bool
    {
        $commands = app(DeviceCommandService::class);
        return $this->canManageRules() && $commands->available($this->getOwnerRecord(), 'set_access_group')
            && $commands->available($this->getOwnerRecord(), 'set_access_period');
    }

    private function ruleFields(): array
    {
        return [
            TextInput::make('name')->label('Rule name')->placeholder('Office hours')->required()->maxLength(100),
            CheckboxList::make('days')->label('Allowed days')->options(DeviceAccessSettings::DAYS)
                ->default(['Mon', 'Tues', 'Wed', 'Thurs', 'Fri'])->required()->minItems(1)->columns(['default' => 2, 'md' => 4]),
            Grid::make(['default' => 1, 'sm' => 2])->schema([
                TimePicker::make('start_time')->label('Access starts')->default('09:00')->seconds(false)->format('H:i')->native()->required(),
                TimePicker::make('end_time')->label('Access ends')->default('17:00')->seconds(false)->format('H:i')->native()->required()->after('start_time'),
            ]),
            Select::make('holidays')->label('Holidays')->options(['0' => 'Use weekly hours', '1' => 'Use device holiday rules'])
                ->default('0')->required()->helperText('Holiday dates and hours are configured on the terminal.'),
            Hidden::make('revision'), Hidden::make('period_revision'),
        ];
    }

    private static function formData(DeviceAccessSetting $record): array
    {
        $period = $record->managedPeriod;
        $hours = $period?->desired ?? [];
        $days = [];
        foreach (DeviceAccessSettings::DAYS as $day => $label) {
            if (isset($hours[$day.'Start'], $hours[$day.'End']) && $hours[$day.'Start'] < $hours[$day.'End']) { $days[] = $day; }
        }
        $first = $days[0] ?? null;
        $format = fn ($value) => substr($value, 0, 2).':'.substr($value, 2, 2);
        return ['name' => $record->name, 'days' => $days,
            'start_time' => $first ? $format($hours[$first.'Start']) : null,
            'end_time' => $first ? $format($hours[$first.'End']) : null,
            'holidays' => $record->desired['ValidHoliday'] ?? '0',
            'revision' => $record->revision, 'period_revision' => $period?->revision];
    }

    private static function hoursLabel(DeviceAccessSetting $record): string
    {
        if (! $record->managed_period_id) { return 'Imported device rule'; }
        $data = self::formData($record);
        $days = array_map(fn ($day) => substr(DeviceAccessSettings::DAYS[$day], 0, 3), $data['days']);
        return implode(', ', $days).' · '.$data['start_time'].'–'.$data['end_time'];
    }

    public function table(Table $table): Table
    {
        return $table->modifyQueryUsing(fn ($query) => $query->with(['command', 'managedPeriod.command']))->columns([
            TextColumn::make('name')->label('Access rule')->searchable()->wrap(),
            TextColumn::make('hours')->label('Allowed access')->state(fn (DeviceAccessSetting $record) => self::hoursLabel($record))->wrap(),
            TextColumn::make('state')->label('Status')->state(fn (DeviceAccessSetting $record) => match ($record->command?->status) {
                'pending' => 'Queued', 'sent' => 'Waiting for device', 'acknowledged' => $record->reported == $record->desired ? 'Readback matches' : 'Device acknowledged',
                'failed', 'expired', 'cancelled' => 'Needs attention', default => $record->reported ? 'Read from device' : 'Draft',
            }),
        ])->defaultSort('name')->description('Choose when employees may enter. Saving a rule does not change the device.')
            ->emptyStateHeading('No access rules yet')->emptyStateDescription('Add a rule with the days and hours when access is allowed.')
            ->headerActions([
                Action::make('readAccessSettings')->label('Read device rules')->icon('heroicon-o-arrow-path')->authorize(fn () => $this->canManageRules())
                    ->action(function (): void {
                        foreach (['query_access_periods', 'query_access_groups'] as $operation) { app(DeviceCommandService::class)->request($this->getOwnerRecord(), $operation); }
                        Notification::make()->title('Device read queued')->success()->send();
                    }),
                Action::make('addAccessRule')->label('Add access rule')->icon('heroicon-o-plus')->authorize(fn () => $this->canManageRules())
                    ->modalWidth('lg')->schema($this->ruleFields())->modalSubmitActionLabel('Save rule')
                    ->action(function (array $data): void {
                        app(DeviceAccessRules::class)->save($this->getOwnerRecord(), $data);
                        Notification::make()->title('Access rule saved')->success()->send();
                    }),
            ])->recordActions([
                Action::make('viewAccessRule')->label('Details')->modalSubmitAction(false)->modalCancelActionLabel('Close')->schema([
                    TextEntry::make('rule_name')->label('Rule')->state(fn (DeviceAccessSetting $record) => $record->name),
                    TextEntry::make('rule_hours')->label('Allowed access')->state(fn (DeviceAccessSetting $record) => self::hoursLabel($record)),
                    TextEntry::make('holiday_behavior')->label('Holidays')->state(fn (DeviceAccessSetting $record) => ($record->desired['ValidHoliday'] ?? '0') === '1' ? 'Device holiday rules' : 'Weekly hours'),
                ]),
                Action::make('editAccessRule')->label('Edit')->authorize(fn () => $this->canManageRules())->visible(fn (DeviceAccessSetting $record) => (bool) $record->managed_period_id)
                    ->modalWidth('lg')->schema($this->ruleFields())->fillForm(fn (DeviceAccessSetting $record) => self::formData($record))->modalSubmitActionLabel('Save rule')
                    ->action(function (DeviceAccessSetting $record, array $data): void {
                        app(DeviceAccessRules::class)->save($this->getOwnerRecord(), $data, $record);
                        Notification::make()->title('Access rule saved')->success()->send();
                    }),
                Action::make('sendAccessRule')->label('Send to device')->icon('heroicon-o-arrow-up-tray')->authorize(fn () => $this->canSendRules())
                    ->visible(fn (DeviceAccessSetting $record) => (bool) $record->managed_period_id && $this->canSendRules())->requiresConfirmation()
                    ->modalDescription('This updates the hours for everyone assigned to this rule.')
                    ->schema([Hidden::make('revision'), Hidden::make('period_revision')])
                    ->fillForm(fn (DeviceAccessSetting $record) => ['revision' => $record->revision, 'period_revision' => $record->managedPeriod?->revision])
                    ->action(function (DeviceAccessSetting $record, array $data): void {
                        app(DeviceAccessRules::class)->apply($this->getOwnerRecord(), $record, (int) $data['revision'], (int) $data['period_revision']);
                        Notification::make()->title('Access rule queued')->body('The hours are sent before the rule is activated.')->success()->send();
                    }),
            ])->toolbarActions([]);
    }
}
