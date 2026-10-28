<?php

namespace App\Filament\Forms\StateCasts;

use App\Services\Support\TenantTimezone;
use Carbon\Exceptions\InvalidFormatException;
use Filament\Schemas\Components\StateCasts\Contracts\StateCast;

final class LocalDateTimeStateCast implements StateCast
{
    public function __construct(private string $format, private string $internalFormat) {}

    private function format(mixed $value, string $format): ?string
    {
        if (blank($value)) {
            return null;
        }
        try {
            return TenantTimezone::parse($value)->setTimezone(TenantTimezone::get())->format($format);
        } catch (InvalidFormatException) {
            // Keep invalid user input for the field's date validation, rather than silently clearing it.
            return (string) $value;
        }
    }

    public function get(mixed $state): ?string
    {
        return $this->format($state, $this->format);
    }

    public function set(mixed $state): ?string
    {
        return $this->format($state, $this->internalFormat);
    }
}
