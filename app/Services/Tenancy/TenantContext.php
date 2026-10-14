<?php

namespace App\Services\Tenancy;

use App\Models\Organisation;

final class TenantContext
{
    public static function run(Organisation $organisation, callable $callback): mixed
    {
        $previous = tenant();
        try {
            tenancy()->initialize($organisation);

            return $callback();
        } finally {
            tenancy()->end();
            if ($previous) {
                tenancy()->initialize($previous);
            }
        }
    }
}
