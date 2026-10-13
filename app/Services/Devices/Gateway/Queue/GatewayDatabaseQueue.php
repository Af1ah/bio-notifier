<?php

namespace App\Services\Devices\Gateway\Queue;

use Illuminate\Queue\DatabaseQueue;

class GatewayDatabaseQueue extends DatabaseQueue
{
    protected function createObjectPayload($job, $queue)
    {
        $payload = parent::createObjectPayload($job, $queue);
        if (method_exists($job, 'signalId')) {
            $payload['gateway_signal_key'] = $job->signalId();
        }

        return $payload;
    }

    protected function pushToDatabase($queue, $payload, $delay = 0, $attempts = 0)
    {
        $key = json_decode($payload, true)['gateway_signal_key'] ?? null;
        if ($key === null) {
            return parent::pushToDatabase($queue, $payload, $delay, $attempts);
        }

        $record = $this->buildDatabaseRecord($this->getQueue($queue), $payload, $this->availableAt($delay), $attempts);
        $record['gateway_signal_key'] = $key;
        $this->database->table($this->table)->insertOrIgnore($record);

        return $this->database->table($this->table)->where('gateway_signal_key', $key)->value('id');
    }

    public function bulk($jobs, $data = '', $queue = null)
    {
        foreach ($jobs as $job) {
            $this->push($job, $data, $queue);
        }
    }
}
