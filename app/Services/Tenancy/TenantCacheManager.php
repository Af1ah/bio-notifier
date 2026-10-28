<?php

namespace App\Services\Tenancy;

use Stancl\Tenancy\CacheManager;

class TenantCacheManager extends CacheManager
{
    protected function createRedisDriver(array $config)
    {
        $connection = $config['connection'] ?? 'default';
        $store = new TenantRedisStore(
            $this->app['redis'],
            $this->getPrefix($config).'tenant:'.hash('sha256', (string) tenant('id')).':',
            $connection,
            $this->getSerializableClasses($config),
        );

        return $this->repository($store->setLockConnection($config['lock_connection'] ?? $connection), $config);
    }

    protected function createDatabaseDriver(array $config)
    {
        $connection = $config['connection'] ?? config('tenancy.database.central_connection');
        $store = new TenantDatabaseCacheStore(
            $this->app['db']->connection($connection), $config['table'],
            $this->getPrefix($config).'tenant:'.hash('sha256', (string) tenant('id')).':',
            $config['lock_table'] ?? 'cache_locks', $config['lock_lottery'] ?? [2, 100],
            $config['lock_timeout'] ?? 86400, $this->getSerializableClasses($config),
        );

        return $this->repository($store->setLockConnection(
            $this->app['db']->connection($config['lock_connection'] ?? $connection)
        ), $config);
    }

    public function __call($method, $parameters)
    {
        if ($method !== 'tags' && $this->store()->getStore() instanceof TenantDatabaseCacheStore) {
            return $this->store()->$method(...$parameters);
        }

        return parent::__call($method, $parameters);
    }
}
