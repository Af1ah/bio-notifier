<?php

namespace App\Providers\Filament;

use App\Filament\Master\Pages\Dashboard;
use App\Filament\Master\Resources\Organisations\OrganisationResource;
use App\Filament\Support\PanelPwa;
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

class MasterPanelProvider extends PanelProvider
{
    public function panel(Panel $panel): Panel
    {
        $panel = $panel
            ->id('master')
            ->path('master')
            ->login()
            ->favicon(asset('icon-192-v3.png'))
            ->colors([
                'primary' => Color::Amber,
            ])
            ->resources([OrganisationResource::class])
            ->discoverPages(in: app_path('Filament/Master/Pages'), for: 'App\Filament\Master\Pages')
            ->pages([
                Dashboard::class,
            ])
            ->spa()
            ->unsavedChangesAlerts()
            ->widgets([
            ])
            ->middleware([
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
            ])
            ->authGuard('admin')
            ->userMenuItems([
                Action::make('installApp')
                    ->label('Install app')
                    ->icon('heroicon-o-arrow-down-tray')
                    ->url('#')
                    ->sort(100)
                    ->extraAttributes(['id' => 'pwa-install-menu-item', 'style' => 'display: none', 'x-on:click.prevent' => 'window.pwaInstall && window.pwaInstall()']),
            ]);

        return PanelPwa::configure($panel, 'master');
    }
}
