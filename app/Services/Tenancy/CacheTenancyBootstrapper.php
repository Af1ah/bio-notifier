<?php

namespace App\Services\Tenancy;

use Stancl\Tenancy\Contracts\Tenant;

class CacheTenancyBootstrapper extends \Stancl\Tenancy\Bootstrappers\CacheTenancyBootstrapper
{
    public function bootstrap(Tenant $tenant)
    {
        $this->app->forgetInstance('cache.store');
        $this->resetFacadeCache();
        $this->originalCache = $this->originalCache ?? $this->app['cache'];
        $this->app->extend('cache', fn () => new TenantCacheManager($this->app));
    }

    public function revert()
    {
        $this->app->forgetInstance('cache.store');
        parent::revert();
    }
}
