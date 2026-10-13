<?php

declare(strict_types=1);

namespace App\Services\Imports;

final readonly class UserImportRow
{
    /** @param array<string, string|int|bool> $attributes
     * @param  array<string, string|int|bool>  $payroll
     * @param  list<int>|null  $taskGroupIds
     */
    public function __construct(
        public int $line,
        public string $pin,
        public array $attributes,
        public array $payroll,
        public ?array $taskGroupIds,
    ) {}
}
