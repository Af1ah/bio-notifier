<?php

namespace App\Filament\Employee\Pages;

use App\Services\EmployeePortal\PortalAccess;
use Filament\Forms\Components\TextInput;
use Filament\Pages\Page;
use Filament\Schemas\Schema;
use Illuminate\Validation\Rules\Password;

class ChangePassword extends Page
{
    protected static bool $shouldRegisterNavigation = false;

    protected string $view = 'filament.employee.pages.change-password';

    public ?array $data = [];

    public function mount(): void
    {
        PortalAccess::employee(allowPasswordChange: true);
        $this->form->fill();
    }

    public function form(Schema $schema): Schema
    {
        return $schema->components([
            TextInput::make('current_password')->label('Current password')->password()->autocomplete('current-password')
                ->visible(fn () => ! PortalAccess::employee(allowPasswordChange: true)->requires_password_change)
                ->required(fn () => ! PortalAccess::employee(allowPasswordChange: true)->requires_password_change),
            TextInput::make('password')->label('New password')->password()->revealable()->autocomplete('new-password')
                ->required()->rule(Password::min(12)->mixedCase()->numbers()->symbols())->confirmed()
                ->helperText('At least 12 characters, with uppercase, lowercase, a number and a symbol.'),
            TextInput::make('password_confirmation')->label('Confirm password')->password()->required()->autocomplete('new-password'),
        ])->statePath('data');
    }

    public function save(): void
    {
        $state = $this->form->getState();
        app(PortalAccess::class)->changePassword(PortalAccess::employee(allowPasswordChange: true), $state);
        auth('employee')->user()->refresh();
        session()->put('password_hash_employee', auth('employee')->user()->getAuthPassword());
        session()->regenerate();
        $this->redirect(Dashboard::getUrl(['tenant' => tenant('shortname')], panel: 'employee'), navigate: true);
    }
}
