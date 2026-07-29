<?php

namespace App\Events;

use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;
use App\Models\Device;
use App\Models\User;

class UserSynced
{
    use Dispatchable;
    use SerializesModels;

    public function __construct(
        public User $user,
        public Device $device
    ) {}
}
