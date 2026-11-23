<?php

namespace App\Services\Backups;

use RuntimeException;

final class BackupTransferException extends RuntimeException
{
    public function __construct(public readonly string $reason, public readonly bool $retryable = true)
    {
        parent::__construct($reason);
    }
}
