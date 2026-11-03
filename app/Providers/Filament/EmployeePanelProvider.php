<?php

namespace App\Providers\Filament;

use App\Filament\Employee\Auth\Login;
use App\Filament\Employee\Pages;
use App\Filament\Support\PanelPwa;
use App\Filament\Tenant\TenantPanel;
use App\Http\Middleware\InitializeTenancyByShortname;
use App\Http\Middleware\RequireEmployeePasswordChange;
use Filament\Actions\Action;
use Filament\Http\Middleware\Authenticate;
use Filament\Http\Middleware\AuthenticateSession;
use Filament\Http\Middleware\DisableBladeIconComponents;
use Filament\Http\Middleware\DispatchServingFilamentEvent;
use Filament\Panel;
use Filament\PanelProvider;
use Filament\Support\Colors\Color;
use Illuminate\Cookie\Middleware\AddQueuedCookiesToResponse;
use Illuminate\Cookie\Middleware\EncryptCookies;
use Illuminate\Foundation\Http\Middleware\VerifyCsrfToken;
use Illuminate\Routing\Middleware\SubstituteBindings;
use Illuminate\Session\Middleware\StartSession;
use Illuminate\View\Middleware\ShareErrorsFromSession;

class EmployeePanelProvider extends PanelProvider
{
    public function panel(Panel $panel): Panel
    {
        $panel = TenantPanel::make()->id('employee')->path('{tenant}/employee')->authGuard('employee')->login(Login::class)
            ->brandName(fn () => tenant()?->name ?? 'BIO-Notifier')
            ->favicon(asset('icon-192-v3.png'))->colors(['primary' => Color::Amber])
            ->pages([Pages\Dashboard::class, Pages\Calendar::class, Pages\Attendance::class, Pages\LeaveRequests::class, Pages\ChangePassword::class])
            ->userMenuItems([
                Action::make('password')->label('Change password')->icon('heroicon-o-key')
                    ->url(fn () => Pages\ChangePassword::getUrl(['tenant' => tenant('shortname')], panel: 'employee')),
                Action::make('installApp')->label('Install app')->icon('heroicon-o-arrow-down-tray')->url('#')->sort(100)
                    ->extraAttributes(['id' => 'pwa-install-menu-item', 'style' => 'display: none', 'x-on:click.prevent' => 'window.pwaInstall && window.pwaInstall()']),
            ])
            ->spa()->unsavedChangesAlerts()->maxContentWidth('5xl')
            ->middleware([InitializeTenancyByShortname::class, EncryptCookies::class, AddQueuedCookiesToResponse::class,
                StartSession::class, AuthenticateSession::class, ShareErrorsFromSession::class, VerifyCsrfToken::class,
                SubstituteBindings::class, DisableBladeIconComponents::class, DispatchServingFilamentEvent::class])
            ->authMiddleware([Authenticate::class, RequireEmployeePasswordChange::class], isPersistent: true);

        return PanelPwa::configure($panel, 'employee');
    }
}
