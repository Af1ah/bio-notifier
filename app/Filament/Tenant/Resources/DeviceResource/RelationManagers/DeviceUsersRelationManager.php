<?php

namespace App\Filament\Tenant\Resources\DeviceResource\RelationManagers;

use App\Filament\Tenant\Actions\DeviceAccessPeriodAction;
use App\Models\DeviceAccessSetting;
use App\Models\DeviceRosterEntry;
use App\Services\Devices\DeviceAccessSettings;
use App\Services\Devices\DeviceCommandService;
use App\Services\Devices\DeviceIntegration;
use App\Services\Devices\DeviceProfilePolicy;
use App\Services\Devices\DeviceUserAccess;
use App\Services\Support\TenantTimezone;
use Filament\Actions\Action;
use Filament\Forms\Components\Hidden;
use Filament\Forms\Components\Select;
use Filament\Infolists\Components\TextEntry;
use Filament\Notifications\Notification;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

class DeviceUsersRelationManager extends RelationManager
{
    protected static string $relationship = 'deviceUsers';

    protected static ?string $title = 'Device users';

    public static function canViewForRecord(Model $ownerRecord, string $pageClass): bool
    {
        return tenant()?->device_integration === DeviceIntegration::GATEWAY;
    }

    private function accessSyncAvailable(): bool
    {
        $key = 'device-user-access-sync:'.tenant('id').':'.$this->getOwnerRecord()->id;
        if (! request()->attributes->has($key)) {
            request()->attributes->set($key, app(DeviceCommandService::class)->available($this->getOwnerRecord(), 'set_user_access_group')
                && app(DeviceCommandService::class)->available($this->getOwnerRecord(), 'query_users'));
        }

        return request()->attributes->get($key);
    }

    public function table(Table $table): Table
    {
        return $table->columns([
            TextColumn::make('pin')->label('PIN')->searchable(),
            TextColumn::make('name')->searchable(),
            TextColumn::make('card')->label('Card number'),
            TextColumn::make('expiration_rule')->label('Expiration rule')
                ->getStateUsing(fn (DeviceRosterEntry $record): string => DeviceUserAccess::expiry($record)),
            TextColumn::make('expiration_start')->label('Start date')
                ->getStateUsing(fn (DeviceRosterEntry $record): string => DeviceUserAccess::date($record->access_settings['StartDatetime'] ?? null)),
            TextColumn::make('expiration_end')->label('End date')
                ->getStateUsing(fn (DeviceRosterEntry $record): string => DeviceUserAccess::date($record->access_settings['EndDatetime'] ?? null)),
            TextColumn::make('remaining_entries')->label('Remaining entries')
                ->getStateUsing(fn (DeviceRosterEntry $record): string => DeviceUserAccess::entries($record)),
            TextColumn::make('daily_periods')->label('Daily time periods')->wrap()
                ->getStateUsing(fn (DeviceRosterEntry $record): string => DeviceUserAccess::periods($record)),
            TextColumn::make('access_received_at')->label('Access last received')
                ->dateTime(timezone: fn () => TenantTimezone::get())->placeholder('Not read yet'),
            TextColumn::make('updated_at')->label('User last received')
                ->dateTime(timezone: fn () => TenantTimezone::get()),
        ])->defaultSort('pin')
            ->description('Access settings belong to this device user. Missing values do not mean unrestricted access. Use Read device users to refresh the snapshot.')
            ->headerActions([
                Action::make('readDeviceUsers')->label('Read device users')->icon('heroicon-o-arrow-path')
                    ->authorize(fn (): bool => (bool) auth('web')->user()?->has_login && app(DeviceCommandService::class)->available($this->getOwnerRecord(), 'query_users'))
                    ->action(function (): void {
                        app(DeviceCommandService::class)->request($this->getOwnerRecord(), 'query_users');
                        Notification::make()->title('Device user read queued')->body('The snapshot updates when the device responds.')->success()->send();
                    }),
            ])
            ->recordActions([
                Action::make('accessDetails')->label('Access settings')->icon('heroicon-o-clock')
                    ->modalHeading('Device user access settings')->modalSubmitAction(false)->modalCancelActionLabel('Close')
                    ->schema([
                        TextEntry::make('expiry_help')->label('Expiration rule')
                            ->state('Expiration dates, remaining entries and daily access hours are independent. Settings omitted from readback must be checked on this terminal.'),
                        TextEntry::make('period_help')->label('Daily time periods')
                            ->state('Group mode uses the group’s daily periods; personal mode uses this user’s period references. Read access settings separately to see their last reported hours. Empty references do not establish allowed or denied access.'),
                        TextEntry::make('reported_expiry')->label('Reported expiration rule')
                            ->getStateUsing(fn (DeviceRosterEntry $record): string => DeviceUserAccess::expiry($record)),
                        TextEntry::make('reported_start')->label('Start date')
                            ->getStateUsing(fn (DeviceRosterEntry $record): string => DeviceUserAccess::date($record->access_settings['StartDatetime'] ?? null)),
                        TextEntry::make('reported_end')->label('End date')
                            ->getStateUsing(fn (DeviceRosterEntry $record): string => DeviceUserAccess::date($record->access_settings['EndDatetime'] ?? null)),
                        TextEntry::make('reported_entries')->label('Remaining entries')
                            ->getStateUsing(fn (DeviceRosterEntry $record): string => DeviceUserAccess::entries($record)),
                        TextEntry::make('reported_periods')->label('Daily time periods')
                            ->getStateUsing(fn (DeviceRosterEntry $record): string => DeviceUserAccess::periods($record)),
                        TextEntry::make('reported_hours')->label('Last reported daily hours')
                            ->getStateUsing(fn (DeviceRosterEntry $record): string => DeviceUserAccess::schedule($record)),
                        TextEntry::make('access_settings.Grp')->label('Reported access group')->placeholder('Not reported'),
                    ]),
                Action::make('editDeviceUserAccess')->label('Edit access')->icon('heroicon-o-pencil-square')
                    ->authorize(fn (): bool => (bool) auth('web')->user()?->has_login && DeviceProfilePolicy::supportsValidity($this->getOwnerRecord()))
                    ->disabled(fn (): bool => ! $this->accessSyncAvailable())
                    ->tooltip(fn (): ?string => config('device_gateway.access_groups_enabled') ? null : 'Device access writes await firmware qualification.')
                    ->modalHeading('Edit device user access')->modalSubmitActionLabel('Save and sync')
                    ->modalDescription('Choose this user’s access group and daily-period mode on this device. Expiration and remaining entries are separate device settings.')
                    ->fillForm(function (DeviceRosterEntry $record): array {
                        $access = $record->access_settings ?? [];
                        $tz = $access['TZ'] ?? '';
                        $personal = preg_match('/^0001[0-9a-fA-F]{12}$/', $tz);
                        $periods = $personal ? array_values(array_filter(array_map(fn ($offset) => (string) hexdec(substr($tz, $offset, 4)), [4, 8, 12]), fn ($slot) => $slot !== '0')) : [];

                        return ['request_id' => (string) Str::uuid(), 'mode' => $personal ? 'personal' : (preg_match('/^0000[0-9a-fA-F]{12}$/', $tz) ? 'group' : null),
                            'group_id' => DeviceAccessSetting::where('device_id', $this->getOwnerRecord()->id)->where('kind', 'group')->where('number', $access['Grp'] ?? 0)->value('id'),
                            'periods' => $periods];
                    })
                    ->schema([
                        Hidden::make('request_id')->required()->rules(['uuid']),
                        Select::make('group_id')->label('Access group')->required()->searchable()
                            ->options(fn () => DeviceAccessSetting::where('device_id', $this->getOwnerRecord()->id)->where('kind', 'group')->orderBy('number')->limit(1000)->get()->mapWithKeys(fn ($group) => [$group->id => $group->number.' · '.$group->name])->all()),
                        Select::make('mode')->label('Daily periods')->options(['group' => 'Use group periods', 'personal' => 'Use personal periods'])->live()->required(),
                        Select::make('periods')->label('Personal periods')->multiple()->minItems(1)->maxItems(3)->required()
                            ->visible(fn ($get): bool => $get('mode') === 'personal')
                            ->options(fn () => DeviceAccessSetting::where('device_id', $this->getOwnerRecord()->id)->where('kind', 'period')->orderBy('number')->limit(1000)->get()->mapWithKeys(fn ($period) => [(string) $period->number => $period->number.' · '.$period->name])->all()),
                        TextEntry::make('current_expiry')->label('Reported expiration rule')->getStateUsing(fn (DeviceRosterEntry $record): string => DeviceUserAccess::expiry($record)),
                        TextEntry::make('current_start')->label('Expiration start')->getStateUsing(fn (DeviceRosterEntry $record): string => DeviceUserAccess::date($record->access_settings['StartDatetime'] ?? null)),
                        TextEntry::make('current_end')->label('Expiration end')->getStateUsing(fn (DeviceRosterEntry $record): string => DeviceUserAccess::date($record->access_settings['EndDatetime'] ?? null)),
                        TextEntry::make('current_entries')->label('Remaining entries')->getStateUsing(fn (DeviceRosterEntry $record): string => DeviceUserAccess::entries($record)),
                    ])
                    ->action(function (DeviceRosterEntry $record, array $data): void {
                        validator($data, ['request_id' => ['required', 'uuid'], 'mode' => ['required', 'in:group,personal']])->validate();
                        $group = DeviceAccessSetting::where('device_id', $this->getOwnerRecord()->id)->where('kind', 'group')->findOrFail($data['group_id']);
                        app(DeviceAccessSettings::class)->assign($this->getOwnerRecord(), $record, $group, $data['mode'] === 'personal' ? ($data['periods'] ?? []) : null, $data['request_id']);
                        Notification::make()->title('User access sync queued')->body('The device-user snapshot refreshes after acknowledgement. Track progress in Device actions.')->success()->send();
                    }),
                DeviceAccessPeriodAction::make($this->getOwnerRecord()),
            ])->toolbarActions([]);
    }
}
