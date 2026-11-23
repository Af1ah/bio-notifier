<?php

use App\Models\GatewayDeviceBinding;
use App\Services\Devices\DeviceAccessSettings;
use Illuminate\Database\Migrations\Migration;

return new class extends Migration
{
    public function up(): void
    {
        GatewayDeviceBinding::orderBy('id')->chunkById(100, function ($bindings): void {
            foreach ($bindings as $binding) {
                $caps = $binding->capabilities ?? [];
                if (in_array('upsert_user', $caps, true) && in_array('get_option', $caps, true)) {
                    $binding->update(['capabilities' => array_values(array_unique([...$caps, ...DeviceAccessSettings::OPERATIONS]))]);
                }
            }
        });
    }

    public function down(): void
    {
        // Existing queued operations retain their authorization during coordinated releases.
    }
};
