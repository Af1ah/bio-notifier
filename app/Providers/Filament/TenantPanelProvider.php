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

class TenantPanelProvider extends PanelProvider
{
    public function panel(Panel $panel): Panel
    {
        // Uncomment the domain line and path('admin') for subdomain routing
        // $centralDomain = parse_url(env('APP_URL', 'http://localhost'), PHP_URL_HOST);

        return $panel
            ->id('tenant')
            // ->domain('{tenant}.' . $centralDomain)
            // ->path('admin')
            ->path('{tenant}/admin')
            ->login()
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
                \Filament\Navigation\NavigationGroup::make()
                     ->label('Organisation Management')
                     ->collapsed(),
                \Filament\Navigation\NavigationGroup::make()
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
                \App\Http\Middleware\InitializeTenancyByShortname::class,
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
            ->renderHook(
                PanelsRenderHook::HEAD_START,
                fn (): HtmlString => new HtmlString('<link rel="manifest" href="/manifest.json?v=4&start_url=' . urlencode('/' . (tenant('shortname') ?? 'tenant') . '/admin') . '"><meta name="theme-color" content="#f59e0b"><meta name="mobile-web-app-capable" content="yes"><meta name="apple-mobile-web-app-capable" content="yes"><meta name="apple-mobile-web-app-status-bar-style" content="black-translucent"><link rel="apple-touch-icon" href="/icon-192-v3.png">')
            )
            ->renderHook(
                PanelsRenderHook::BODY_END,
                fn (): HtmlString => new HtmlString('<script>
                    if ("serviceWorker" in navigator) {
                        window.addEventListener("load", async function() {
                            try {
                                const regs = await navigator.serviceWorker.getRegistrations();
                                for (let r of regs) { await r.unregister(); }
                                
                                const registration = await navigator.serviceWorker.register("/sw.js?v=4");
                                
                                registration.addEventListener("updatefound", () => {
                                    const newWorker = registration.installing;
                                    newWorker.addEventListener("statechange", () => {
                                        if (newWorker.state === "installed" && navigator.serviceWorker.controller) {
                                            // New version available!
                                            document.dispatchEvent(new CustomEvent("pwa-update-available", { detail: registration }));
                                        }
                                    });
                                });

                                let refreshing = false;
                                navigator.serviceWorker.addEventListener("controllerchange", () => {
                                    if (!refreshing) {
                                        refreshing = true;
                                        window.location.reload();
                                    }
                                });
                            } catch (error) {
                                console.error("SW registration failed:", error);
                            }
                        });
                    }
                </script>')
            )
            ->renderHook(
                PanelsRenderHook::GLOBAL_SEARCH_BEFORE,
                fn (): HtmlString => new HtmlString('
                    <div class="px-3 py-1 mr-4 text-sm font-medium text-red-600 bg-red-100 border border-red-200 rounded-lg dark:bg-red-900/30 dark:text-red-400 dark:border-red-800" 
                         style="display: none;" 
                         x-data="{ offline: !navigator.onLine, timeout: null, visible: false }" 
                         x-init="
                             if (offline) { timeout = setTimeout(() => visible = true, 3000); }
                             window.addEventListener(\'offline\', () => { offline = true; timeout = setTimeout(() => visible = true, 3000); });
                             window.addEventListener(\'online\', () => { offline = false; visible = false; clearTimeout(timeout); });
                         "
                         x-bind:style="visible ? \'display: flex; align-items: center; gap: 0.5rem;\' : \'display: none;\'">
                        <svg style="width: 1.25rem; height: 1.25rem;" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M18.364 5.636a9 9 0 010 12.728m0 0l-2.829-2.829m2.829 2.829L21 21M15.536 8.485a5 5 0 010 7.072m0 0l-2.829-2.829m-4.243 2.829a4.978 4.978 0 01-1.414-2.83m-1.414 5.658a9 9 0 01-2.167-9.238m7.824 2.167a1 1 0 111.414 1.414m-1.414-1.414L3 3m8.293 8.293l1.414 1.414"></path></svg>
                        Offline Mode
                    </div>
                    <button id="pwa-install-btn" style="display: none; align-items: center; gap: 0.5rem; background-color: rgba(245, 158, 11, 0.1); color: #f59e0b; border: 1px solid #f59e0b; padding: 0.25rem 0.75rem; border-radius: 0.5rem; font-weight: 600; font-size: 0.875rem; cursor: pointer; transition: all 0.2s;" onmouseover="this.style.backgroundColor=\'#f59e0b\'; this.style.color=\'white\'" onmouseout="this.style.backgroundColor=\'rgba(245, 158, 11, 0.1)\'; this.style.color=\'#f59e0b\'">
                        <svg style="width: 1.25rem; height: 1.25rem;" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 16v1a3 3 0 003 3h10a3 3 0 003-3v-1m-4-4l-4 4m0 0l-4-4m4 4V4"></path></svg>
                        Install App
                    </button>
                    <button id="pwa-update-btn" style="display: none; align-items: center; gap: 0.5rem; background-color: rgba(16, 185, 129, 0.1); color: #10b981; border: 1px solid #10b981; padding: 0.25rem 0.75rem; border-radius: 0.5rem; font-weight: 600; font-size: 0.875rem; cursor: pointer; margin-right: 0.5rem;" onclick="updatePwa()">
                        Update Available
                    </button>
                    <script>
                        var deferredPrompt;
                        var swRegistration;
                        
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

                        document.addEventListener("pwa-update-available", (e) => {
                            swRegistration = e.detail;
                            const updateBtn = document.getElementById("pwa-update-btn");
                            if(updateBtn) updateBtn.style.display = "flex";
                        });

                        function updatePwa() {
                            if (swRegistration && swRegistration.waiting) {
                                swRegistration.waiting.postMessage({ type: "SKIP_WAITING" });
                            }
                        }
                    </script>
                ')
            );
    }
}
