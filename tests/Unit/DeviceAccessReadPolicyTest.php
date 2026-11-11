<?php

namespace Tests\Unit;

use App\Models\Device;
use App\Services\Devices\DeviceProfilePolicy;
use Tests\TestCase;

class DeviceAccessReadPolicyTest extends TestCase
{
    public function test_recognized_families_can_read_access_without_enabling_unverified_writes(): void
    {
        config(['device_gateway.expiration_date_pilot_serial' => null]);
        foreach ([['model' => 'x 2008', 'platform' => 'ZLM60_TFT'], ['biometric_type' => '9', 'platform' => 'ZAM180_TFT']] as $metadata) {
            $device = new Device(['options' => ['observed_metadata' => $metadata]]);
            foreach (['query_access_groups', 'query_access_periods'] as $operation) {
                $this->assertTrue(DeviceProfilePolicy::allows($device, $operation));
            }
            foreach (['set_access_group', 'set_access_period', 'set_user_access_group', 'set_user_validity'] as $operation) {
                $this->assertFalse(DeviceProfilePolicy::allows($device, $operation));
            }
        }
        $this->assertFalse(DeviceProfilePolicy::allows(new Device, 'query_access_groups'));
    }
}
