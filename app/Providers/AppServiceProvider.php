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
        $this->app['queue']->addConnector('gateway-database', fn () => new \App\Services\Devices\Gateway\Queue\GatewayDatabaseConnector($this->app['db']));
        \Illuminate\Support\Facades\Queue::looping(function (\Illuminate\Queue\Events\Looping $event) {
            if (! in_array($event->connectionName, ['database', 'gateway'], true)) return;
            foreach (explode(',', $event->queue) as $queue) {
                if (in_array($queue, ['device-ingest', 'device-commands', 'attendance-live', 'exports-notifications'], true)) {
                    \App\Services\Devices\Gateway\GatewayMonitor::beat($queue, 'worker:'.$queue.':'.getmypid());
                }
            }
        });
        \Illuminate\Support\Facades\Auth::extend('tenant-session', function ($app, $name, array $config) {
            // IDs in different tenant databases can overlap. Scope both the login
            // session key and the remember cookie to the owning organisation.
            $scope = tenancy()->initialized ? (string) tenant('id') : 'central';

            return $app['auth']->createSessionDriver($name.'_'.hash('sha256', $scope), $config);
        });
    }
}
