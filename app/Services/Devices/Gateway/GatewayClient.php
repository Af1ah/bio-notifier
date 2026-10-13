<?php

namespace App\Services\Devices\Gateway;

use App\Models\GatewayConnection;
use Illuminate\Support\Facades\Http;

final class GatewayClient
{
    public function call(GatewayConnection $connection, string $method, string $path, array $payload = []): array
    {
        $origin = rtrim($connection->management_url, '/');
        if (! $connection->enabled || ! in_array($origin, config('device_gateway.allowed_origins'), true) || strlen($connection->management_token) < 32) {
            throw new \RuntimeException('Gateway connection is unavailable.');
        }
        $response = Http::withToken($connection->management_token)->acceptJson()->connectTimeout(3)->timeout(10)
            ->withoutRedirecting()->send($method, $origin.$path, $payload ? ['json' => $payload] : []);
        if (! $response->successful()) {
            throw new GatewayRequestException($response->status());
        }
        $result = $response->json();
        if (! is_array($result)) {
            throw new \RuntimeException('Invalid gateway response.');
        }

        return $result;
    }
}
