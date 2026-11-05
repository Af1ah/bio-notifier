<?php

namespace Tests\Unit;

use App\Models\Device;
use App\Services\Devices\DeviceProfilePolicy;
use Tests\TestCase;

class DeviceExpirationPilotTest extends TestCase
{
    public function test_pilot_only_enables_expiration_for_the_selected_ai_device(): void
    {
        config(['device_gateway.expiration_date_pilot_serial' => 'PILOT01']);
        $device = new Device(['serial_number' => 'PILOT01', 'options' => ['observed_metadata' => ['biometric_type' => '9']]]);
        $this->assertTrue(DeviceProfilePolicy::allows($device, 'set_user_validity'));
        $this->assertFalse(DeviceProfilePolicy::allows($device, 'set_access_group'));
        $device->serial_number = 'OTHER';
        $this->assertFalse(DeviceProfilePolicy::allows($device, 'set_user_validity'));
        $device->serial_number = 'PILOT01';
        $device->options = ['observed_metadata' => ['biometric_type' => '1']];
        $this->assertFalse(DeviceProfilePolicy::allows($device, 'set_user_validity'));
    }
}
