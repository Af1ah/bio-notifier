<?php

namespace App\Services\Devices\Gateway\Queue;

use Illuminate\Queue\Connectors\DatabaseConnector;

class GatewayDatabaseConnector extends DatabaseConnector
{
    public function connect(array $config)
    {
        return new GatewayDatabaseQueue(
            $this->connections->connection($config['connection'] ?? null),
            $config['table'], $config['queue'],
            $config['retry_after'] ?? 120, $config['after_commit'] ?? false,
        );
    }
}
