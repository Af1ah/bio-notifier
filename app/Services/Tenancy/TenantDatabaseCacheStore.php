<?php

namespace App\Services\Tenancy;

use Illuminate\Cache\DatabaseStore;

final class TenantDatabaseCacheStore extends DatabaseStore
{
    public function flush()
    {
        // The namespace ends with ':'; this range includes only its keys.
        $this->table()->where('key', '>=', $this->prefix)
            ->where('key', '<', substr($this->prefix, 0, -1).';')->delete();

        return true;
    }
}
