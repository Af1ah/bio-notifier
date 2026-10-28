<?php

namespace App\Services\Tenancy;

use Illuminate\Cache\RedisStore;
use LogicException;

final class TenantRedisStore extends RedisStore
{
    public function flush()
    {
        // RedisStore::flush uses FLUSHDB and ignores its key prefix. Normal
        // tenant Cache::flush uses RedisTaggedCache and stays tenant-scoped.
        throw new LogicException('Use the tenant-tagged cache to flush tenant values.');
    }
}
