<?php

namespace Tests\Unit;

use App\Models\Device;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class DeviceCommandSelectionTest extends TestCase
{
    public function test_command_choices_put_recently_connected_devices_first_without_changing_default_order(): void
    {
        $this->travelTo(now()->startOfSecond());
        Schema::create('devices', function (Blueprint $table): void {
            $table->id();
            $table->string('name');
            $table->timestamp('last_activity_at')->nullable();
            $table->softDeletes();
            $table->timestamps();
        });
        $offline = Device::create(['name' => 'A offline', 'last_activity_at' => now()->subMinutes(3)]);
        $waiting = Device::create(['name' => 'B waiting']);
        $online = Device::create(['name' => 'Z online', 'last_activity_at' => now()->subMinute()]);
        $future = Device::create(['name' => 'C future', 'last_activity_at' => now()->addMinute()]);
        $removed = Device::create(['name' => 'Removed', 'last_activity_at' => now()]);
        $removed->delete();

        $this->assertSame([$online->id, $offline->id, $waiting->id, $future->id], Device::withTrashed()->forCommandSelection()->pluck('id')->all());
        $this->assertSame([$offline->id, $waiting->id, $online->id, $future->id], Device::query()->pluck('id')->all());
        $this->travelBack();
    }
}
