<?php

namespace App\Filament\Tenant\Pages;

use App\Services\Support\TenantTimezone;
use Filament\Forms\Components\Select;
use Filament\Forms\Concerns\InteractsWithForms;
use Filament\Forms\Contracts\HasForms;
use Filament\Notifications\Notification;
use Filament\Support\Icons\Heroicon;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

class OrganisationSettings extends SettingsPage implements HasForms
{
    use InteractsWithForms;

    protected static ?string $slug = 'organisation-settings';

    protected static string|\BackedEnum|null $navigationIcon = Heroicon::OutlinedCog6Tooth;
    protected static string|\UnitEnum|null $navigationGroup = 'Organisation Management';
    protected static ?int $navigationSort = 99;
    protected string $view = 'filament.tenant.pages.organisation-settings';
    public ?array $data = [];

    public static function getNavigationLabel(): string
    {
        return __('organisation-settings.overview');
    }

    public static function getNavigationItemActiveRoutePattern(): array
    {
        return [static::getRouteName(), Backups::getRouteName(), Integrations::getRouteName()];
    }

    public function mount(): void
    {
        abort_unless(static::canAccess(), 403);
        $this->form->fill(['timezone' => TenantTimezone::get()]);
    }

    public function form(Schema $schema): Schema
    {
        return $schema->components([
            Section::make(__('organisation-settings.time'))->description(__('organisation-settings.time_help'))
                ->schema([
                    Select::make('timezone')->label(__('organisation-settings.timezone'))
                        ->options(array_combine(\DateTimeZone::listIdentifiers(), \DateTimeZone::listIdentifiers()))
                        ->searchable()->required()->rules(['timezone']),
                ]),
        ])->statePath('data');
    }

    public function save(): void
    {
        abort_unless(static::canAccess(), 403);
        tenant()->update(['timezone' => $this->form->getState()['timezone']]);
        Notification::make()->title(__('organisation-settings.timezone_saved'))->success()->send();
    }
}
