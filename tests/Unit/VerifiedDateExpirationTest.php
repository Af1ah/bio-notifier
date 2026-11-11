<?php

namespace Tests\Unit;

use App\Models\Device;
use App\Services\Devices\DeviceProfilePolicy;
use Tests\TestCase;

class VerifiedDateExpirationTest extends TestCase
{
    public function test_verification_is_device_specific_and_does_not_enable_access_writes(): void
    {
        config(['device_gateway.verified_date_expiration_serials' => ['F22VERIFIED']]);
        $device = new Device(['serial_number' => 'F22VERIFIED', 'options' => ['observed_metadata' => ['platform' => 'ZLM60_TFT']]]);
        $this->assertTrue(DeviceProfilePolicy::verifiedDateExpiration($device));
        $this->assertTrue(DeviceProfilePolicy::allows($device, 'set_user_validity'));
        $this->assertFalse(DeviceProfilePolicy::allows($device, 'set_access_group'));
        $device->serial_number = 'OTHER';
        $this->assertFalse(DeviceProfilePolicy::verifiedDateExpiration($device));
        $this->assertFalse(DeviceProfilePolicy::allows($device, 'set_user_validity'));
    }
}
