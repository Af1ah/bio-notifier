<?php

namespace App\Providers;

use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        \Illuminate\Support\Facades\Auth::extend('tenant-session', function ($app, $name, array $config) {
            // IDs in different tenant databases can overlap. Scope both the login
            // session key and the remember cookie to the owning organisation.
            $scope = tenancy()->initialized ? (string) tenant('id') : 'central';

            return $app['auth']->createSessionDriver($name.'_'.hash('sha256', $scope), $config);
        });
    }
}
