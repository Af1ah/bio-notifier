<?php

namespace App\Providers\Filament;

use Filament\Http\Middleware\Authenticate;
use Filament\Http\Middleware\AuthenticateSession;
use Filament\Http\Middleware\DisableBladeIconComponents;
use Filament\Http\Middleware\DispatchServingFilamentEvent;
use Filament\Pages\Dashboard;
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
                    <button id="pwa-install-btn" style="display: none; align-items: center; gap: 0.5rem; background-color: rgba(245, 158, 11, 0.1); color: #f59e0b; border: 1px solid #f59e0b; padding: 0.25rem 0.75rem; border-radius: 0.5rem; font-weight: 600; font-size: 0.875rem; cursor: pointer; transition: all 0.2s;" onmouseover="this.style.backgroundColor=\'#f59e0b\'; this.style.color=\'white\'" onmouseout="this.style.backgroundColor=\'rgba(245, 158, 11, 0.1)\'; this.style.color=\'#f59e0b\'">
                        <svg style="width: 1.25rem; height: 1.25rem;" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 16v1a3 3 0 003 3h10a3 3 0 003-3v-1m-4-4l-4 4m0 0l-4-4m4 4V4"></path></svg>
                        Install App
                    </button>
                    <script>
                        var deferredPrompt;
                        window.addEventListener("beforeinstallprompt", (e) => {
                            e.preventDefault();
                            deferredPrompt = e;
                            const installBtn = document.getElementById("pwa-install-btn");
                            if(installBtn) installBtn.style.display = "flex";
                        });
                        document.addEventListener("click", async (e) => {
                            const btn = e.target.closest("#pwa-install-btn");
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
                            const installBtn = document.getElementById("pwa-install-btn");
                            if(installBtn) installBtn.style.display = "none";
                        });
                    </script>
                ')
            );
    }
}
