<?php

namespace App\Providers\Filament;

use Filament\Http\Middleware\Authenticate;
use Filament\Http\Middleware\AuthenticateSession;
use Filament\Http\Middleware\DisableBladeIconComponents;
use Filament\Http\Middleware\DispatchServingFilamentEvent;
use Filament\Pages\Dashboard;
use Filament\Actions\Action;
use Filament\Panel;
use Filament\PanelProvider;
use Filament\Support\Colors\Color;
use Filament\Widgets\AccountWidget;
use Filament\Widgets\FilamentInfoWidget;
use Illuminate\Cookie\Middleware\AddQueuedCookiesToResponse;
use Illuminate\Cookie\Middleware\EncryptCookies;
use Illuminate\Foundation\Http\Middleware\VerifyCsrfToken;
use Illuminate\Routing\Middleware\SubstituteBindings;
use Illuminate\Session\Middleware\StartSession;
use Illuminate\View\Middleware\ShareErrorsFromSession;
use Illuminate\Support\HtmlString;
use Filament\View\PanelsRenderHook;

class MasterPanelProvider extends PanelProvider
{
    public function panel(Panel $panel): Panel
    {
        return $panel
            ->id('master')
            ->path('master')
            ->login()
            ->favicon(asset('icon-192-v3.png'))
            ->colors([
                'primary' => Color::Amber,
            ])
            ->discoverResources(in: app_path('Filament/Master/Resources'), for: 'App\Filament\Master\Resources')
            ->discoverPages(in: app_path('Filament/Master/Pages'), for: 'App\Filament\Master\Pages')
            ->pages([
                Dashboard::class,
            ])
            ->spa()
            ->unsavedChangesAlerts()
            ->discoverWidgets(in: app_path('Filament/Master/Widgets'), for: 'App\Filament\Master\Widgets')
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
            ])
            ->renderHook(
                PanelsRenderHook::HEAD_START,
                fn (): HtmlString => new HtmlString('<link rel="manifest" href="/manifest.json?v=4&start_url=' . urlencode('/master') . '"><meta name="theme-color" content="#f59e0b"><meta name="mobile-web-app-capable" content="yes"><meta name="apple-mobile-web-app-capable" content="yes"><meta name="apple-mobile-web-app-status-bar-style" content="black-translucent"><link rel="apple-touch-icon" href="/icon-192-v3.png">')
            )
            ->renderHook(
                PanelsRenderHook::BODY_END,
                fn (): HtmlString => new HtmlString('<script>if ("serviceWorker" in navigator) { window.addEventListener("load", async function() { const regs = await navigator.serviceWorker.getRegistrations(); for (let r of regs) { await r.unregister(); } navigator.serviceWorker.register("/sw.js?v=4"); }); }</script>')
            )
            ->renderHook(
                PanelsRenderHook::GLOBAL_SEARCH_BEFORE,
                fn (): HtmlString => new HtmlString('
                    <script>
                        var deferredPrompt;
                        window.addEventListener("beforeinstallprompt", (e) => {
                            e.preventDefault();
                            deferredPrompt = e;
                            const installBtn = document.getElementById("pwa-install-menu-item");
                            if(installBtn) installBtn.style.display = "flex";
                        });
                        document.addEventListener("click", async (e) => {
                            const btn = e.target.closest("#pwa-install-menu-item");
                            if (btn && deferredPrompt) {
                                deferredPrompt.prompt();
                                const { outcome } = await deferredPrompt.userChoice;
                                if (outcome === "accepted") {
                                    btn.style.display = "none";
                                }
                                deferredPrompt = null;
                            }
                        });
                        window.addEventListener("appinstalled", () => {
                            const installBtn = document.getElementById("pwa-install-menu-item");
                            if(installBtn) installBtn.style.display = "none";
                        });
                    </script>
                ')
            );
    }
}
