<?php

namespace App\Events;

use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;
use App\Models\Device;

class DeviceConnected
{
    use Dispatchable;
    use SerializesModels;

    public function __construct(
        public Device $device
    ) {}
}
