<?php

namespace App\Services\Devices\Gateway;

use Illuminate\Support\Facades\DB;

final class GatewayMonitor
{
    private static array $lastBeat = [];

    public static function beat(string $role, string $id, array $details = [], ?string $organisationId = null): void
    {
        if ((self::$lastBeat[$id] ?? 0) > time() - 15) {
            return;
        }
        $connection = DB::connection(config('tenancy.database.central_connection'));
        try {
            $connection->table('gateway_runtime_states')->updateOrInsert(['id' => $id], [
                'role' => $role, 'organisation_id' => $organisationId,
                'details' => json_encode($details), 'heartbeat_at' => now(),
            ]);
            self::$lastBeat[$id] = time();
        } catch (\Throwable $exception) {
            // Telemetry is not a reason to fail payroll or stop a worker during rollout.
        }
    }
}
