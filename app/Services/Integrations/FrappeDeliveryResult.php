<?php

namespace App\Services\Integrations;

final readonly class FrappeDeliveryResult
{
    public function __construct(
        public ?string $error = null,
        public ?string $outcome = null,
        public int $cooldownSeconds = 0,
    ) {}

    public function retryable(): bool
    {
        return in_array($this->error, ['connection', 'unexpected_response', 'http_408', 'http_425', 'http_429'], true)
            || str_starts_with($this->error ?? '', 'http_5');
    }
}
