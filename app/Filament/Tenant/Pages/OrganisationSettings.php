<?php

namespace App\Filament\Tenant\Pages;

use App\Services\Support\TenantTimezone;
use Filament\Forms\Components\Select;
use Filament\Forms\Concerns\InteractsWithForms;
use Filament\Forms\Contracts\HasForms;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

class OrganisationSettings extends Page implements HasForms
{
    use InteractsWithForms;

    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-cog-6-tooth';
    protected static string|\UnitEnum|null $navigationGroup = 'Organisation Management';
    protected static ?int $navigationSort = 99;
    protected string $view = 'filament.tenant.pages.organisation-settings';
    public ?array $data = [];

    public static function canAccess(): bool
    {
        return tenant() && (bool) auth('web')->user()?->has_login;
    }

    public function mount(): void
    {
        abort_unless(static::canAccess(), 403);
        $this->form->fill(['timezone' => TenantTimezone::get()]);
    }

    public function form(Schema $schema): Schema
    {
        return $schema->components([
            Section::make('Time display')->description('Choose the timezone for device activity and command timestamps. Existing attendance punch times are preserved.')
                ->schema([
                    Select::make('timezone')->label('Organisation timezone')
                        ->options(array_combine(\DateTimeZone::listIdentifiers(), \DateTimeZone::listIdentifiers()))
                        ->searchable()->required()->rules(['timezone']),
                ]),
        ])->statePath('data');
    }

    public function save(): void
    {
        abort_unless(static::canAccess(), 403);
        tenant()->update(['timezone' => $this->form->getState()['timezone']]);
        Notification::make()->title('Timezone saved')->success()->send();
    }
}
