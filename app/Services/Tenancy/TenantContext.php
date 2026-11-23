<?php

namespace App\Services\Tenancy;

use App\Models\Organisation;
use Illuminate\Support\Facades\DB;
use LogicException;

final class TenantContext
{
    public static function run(Organisation $organisation, callable $callback): mixed
    {
        $previous = tenant();
        // The caller owns an already-active context and its transaction.
        // Ending it here would disconnect and roll back the caller's writes.
        if (tenancy()->initialized && $previous?->getTenantKey() === $organisation->getTenantKey()) {
            return $callback();
        }

        // Switching databases would discard the previous tenant's open transaction.
        if (tenancy()->initialized && DB::connection('tenant')->transactionLevel() > 0) {
            throw new LogicException('Finish the active tenant transaction before switching organisations.');
        }

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
