<?php

namespace App\Filament\Forms\Components;

use App\Filament\Forms\StateCasts\LocalDateTimeStateCast;
use App\Services\Support\TenantTimezone;
use Filament\Forms\Components\DateTimePicker;
use Filament\Schemas\Components\StateCasts\DateTimeStateCast;

// Attendance source columns contain local wall time, not UTC instants.
class LocalDateTimePicker extends DateTimePicker
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->timezone(fn () => TenantTimezone::get());
    }

    public function getDefaultStateCasts(): array
    {
        return [
            ...array_filter(parent::getDefaultStateCasts(), fn ($cast) => ! $cast instanceof DateTimeStateCast),
            new LocalDateTimeStateCast($this->getFormat(), $this->getInternalFormat()),
        ];
    }
}
