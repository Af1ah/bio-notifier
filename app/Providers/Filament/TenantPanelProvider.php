<?php

namespace App\Providers\Filament;

use App\Filament\Support\PanelPwa;
use App\Filament\Tenant\Pages\OrganisationSettings;
use App\Filament\Tenant\TenantPanel;
use App\Http\Middleware\InitializeTenancyByShortname;
use App\Livewire\Tenant\ResultNotifications;
use Filament\Actions\Action;
use Filament\Http\Middleware\Authenticate;
use Filament\Http\Middleware\AuthenticateSession;
use Filament\Http\Middleware\DisableBladeIconComponents;
use Filament\Http\Middleware\DispatchServingFilamentEvent;
use Filament\Navigation\NavigationGroup;
use Filament\Pages\Dashboard;
use Filament\Panel;
use Filament\PanelProvider;
use Filament\Support\Colors\Color;
use Illuminate\Cookie\Middleware\AddQueuedCookiesToResponse;
use Illuminate\Cookie\Middleware\EncryptCookies;
use Illuminate\Foundation\Http\Middleware\VerifyCsrfToken;
use Illuminate\Routing\Middleware\SubstituteBindings;
use Illuminate\Session\Middleware\StartSession;
use Illuminate\View\Middleware\ShareErrorsFromSession;

class TenantPanelProvider extends PanelProvider
{
    public function panel(Panel $panel): Panel
    {
        // Uncomment the domain line and path('admin') for subdomain routing
        // $centralDomain = parse_url(env('APP_URL', 'http://localhost'), PHP_URL_HOST);

        $panel = TenantPanel::make()
            ->id('tenant')
            // ->domain('{tenant}.' . $centralDomain)
            // ->path('admin')
            ->path('{tenant}/admin')
            ->login()
            ->databaseNotifications(livewireComponent: ResultNotifications::class)
            ->databaseNotificationsPolling('15s')
            ->userMenuItems([
                Action::make('organisationSettings')
                    ->label('Organisation settings')
                    ->icon('heroicon-o-cog-6-tooth')
                    ->url(fn () => OrganisationSettings::getUrl(['tenant' => tenant('shortname')], panel: 'tenant'))
                    ->visible(fn (): bool => OrganisationSettings::canAccess())
                    ->sort(90),
                Action::make('installApp')
                    ->label('Install app')
                    ->icon('heroicon-o-arrow-down-tray')
                    ->url('#')
                    ->sort(100)
                    ->extraAttributes(['id' => 'pwa-install-menu-item', 'style' => 'display: none', 'x-on:click.prevent' => 'window.pwaInstall && window.pwaInstall()']),
            ])
            ->brandName(fn () => tenant()?->name ?? 'BIO-Notifier')
            ->favicon(asset('icon-192-v3.png'))
            ->colors([
                'primary' => Color::Amber,
            ])
            ->discoverResources(in: app_path('Filament/Tenant/Resources'), for: 'App\Filament\Tenant\Resources')
            ->discoverPages(in: app_path('Filament/Tenant/Pages'), for: 'App\Filament\Tenant\Pages')
            ->pages([
                Dashboard::class,
            ])
            ->navigationGroups([
                NavigationGroup::make()
                    ->label('Organisation Management')
                    ->collapsed(),
                NavigationGroup::make()
                    ->label('Payroll')
                    ->collapsed(),
                NavigationGroup::make()
                    ->label('Device Management')
                    ->collapsed(),
            ])
            ->spa()
            ->unsavedChangesAlerts()
            ->databaseTransactions()
            ->discoverWidgets(in: app_path('Filament/Tenant/Widgets'), for: 'App\Filament\Tenant\Widgets')
            ->widgets([
            ])
            ->middleware([
                // \Stancl\Tenancy\Middleware\InitializeTenancyByDomain::class,
                InitializeTenancyByShortname::class,
                EncryptCookies::class,
                AddQueuedCookiesToResponse::class,
                StartSession::class,
                AuthenticateSession::class,
                ShareErrorsFromSession::class,
                VerifyCsrfToken::class,
                SubstituteBindings::class,
                DisableBladeIconComponents::class,
                DispatchServingFilamentEvent::class,
            ])
            ->authMiddleware([
                Authenticate::class,
            ]);

        return PanelPwa::configure($panel);
    }
}
