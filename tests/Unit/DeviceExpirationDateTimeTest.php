<?php

namespace Tests\Unit;

use App\Services\Devices\DeviceAccessValidity;
use App\Services\Devices\DeviceUserAccess;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class DeviceExpirationDateTimeTest extends TestCase
{
    public function test_date_time_and_count_are_validated_and_read_without_losing_time(): void
    {
        $input = ['pin' => '7', 'mode' => 'both', 'from' => '2026-11-05T09:30:00', 'until' => '2026-11-10T18:45:00', 'entries' => '3'];
        $this->assertSame($input, DeviceAccessValidity::parameters($input));
        $this->assertSame('2026-11-05 09:30:00', DeviceUserAccess::date('20261105093000'));
        $this->assertSame('Unrecognized date', DeviceUserAccess::date('20261105253000'));
        $this->expectException(ValidationException::class);
        DeviceAccessValidity::parameters(array_replace($input, ['until' => '2026-11-05T08:00:00']));
    }
}
