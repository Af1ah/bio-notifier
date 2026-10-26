<?php

use App\Models\GatewayDeviceBinding;
use Illuminate\Database\Migrations\Migration;

return new class extends Migration
{
    public function up(): void
    {
        GatewayDeviceBinding::orderBy('id')->chunkById(100, function ($bindings) {
            foreach ($bindings as $binding) {
                $caps = $binding->capabilities ?? [];
                if (in_array('upsert_user', $caps, true) && in_array('get_option', $caps, true) && ! in_array('sync_time', $caps, true)) {
                    $binding->update(['capabilities' => [...$caps, 'sync_time']]);
                }
            }
        });
    }

    public function down(): void
    {
        // Retain capability for queued clock commands and coordinated gateway upgrades.
    }
};
