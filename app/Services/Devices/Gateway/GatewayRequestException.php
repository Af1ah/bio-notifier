<?php

namespace App\Services\Devices\Gateway;

final class GatewayRequestException extends \RuntimeException
{
    public function __construct(public readonly int $status)
    {
        parent::__construct('Gateway request failed.');
    }
}
