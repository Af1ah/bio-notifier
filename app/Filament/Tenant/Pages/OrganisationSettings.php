<?php

namespace App\Filament\Tenant\Pages;

use App\Models\EmployeePortalSetting;
use App\Services\Support\TenantCurrency;
use App\Services\Support\TenantTimezone;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Toggle;
use Filament\Forms\Concerns\InteractsWithForms;
use Filament\Forms\Contracts\HasForms;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Illuminate\Validation\Rule;

class OrganisationSettings extends Page implements HasForms
{
    use InteractsWithForms;

    protected static bool $shouldRegisterNavigation = false;

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
        $this->form->fill(['timezone' => TenantTimezone::get(), 'currency' => TenantCurrency::get(), 'geofencing_enabled' => EmployeePortalSetting::geofencingEnabled()]);
    }

    public function form(Schema $schema): Schema
    {
        return $schema->components([
            Section::make('Employee attendance')->schema([
                Toggle::make('geofencing_enabled')->label('Enable work location verification')
                    ->helperText('Verify the allowed radius when an employee selects a work location.'),
            ]),
            Section::make('Organisation currency')
                ->description('Used automatically for new salary, overtime and leave-payment settings. Saved payroll history keeps its recorded currency.')
                ->schema([
                    Select::make('currency')->label('Currency')->options(TenantCurrency::OPTIONS)
                        ->searchable()->required()->rules([Rule::in(array_keys(TenantCurrency::OPTIONS))]),
                ]),
            Section::make('Organisation time')->description('Used for attendance dates, shifts and displayed timestamps. Choose it before recording attendance; changing existing history requires a reviewed migration.')
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
        $data = $this->form->getState();
        tenant()->update(['timezone' => $data['timezone'], 'currency' => $data['currency']]);
        EmployeePortalSetting::query()->whereKey(1)->firstOrFail()->update(['geofencing_enabled' => (bool) $data['geofencing_enabled']]);
        Notification::make()->title('Organisation settings saved')->success()->send();
    }
}
