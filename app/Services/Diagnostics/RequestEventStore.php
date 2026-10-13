<?php

namespace App\Services\Diagnostics;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class RequestEventStore
{
    public function record(array $event): void
    {
        $connection = config('tenancy.database.central_connection');
        // Rolling deploys may serve requests before the central migration.
        if (! Schema::connection($connection)->hasTable('request_diagnostic_events')) {
            return;
        }
        DB::connection($connection)->transaction(function ($db) use ($event) {
            // One central lock serializes insertion + FIFO eviction, including
            // requests from different tenants sharing this diagnostic store.
            $db->table('request_diagnostic_lock')->where('id', 1)->lockForUpdate()->first();
            $id = $db->table('request_diagnostic_events')->insertGetId([
                'method' => substr((string) ($event['method'] ?? ''), 0, 16),
                'route' => substr((string) ($event['route'] ?? ''), 0, 200),
                'status' => (int) ($event['status'] ?? 0),
                'created_at' => now(),
            ]);
            $db->table('request_diagnostic_events')->where('id', '<=', $id - 10000)->delete();
        }, 3);
    }
}
