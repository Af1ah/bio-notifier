<?php

use App\Models\GatewayDeviceBinding;
use Illuminate\Database\Migrations\Migration;

return new class extends Migration
{
    public function up(): void
    {
        GatewayDeviceBinding::orderBy('id')->chunkById(100, function ($bindings): void {
            foreach ($bindings as $binding) {
                $caps = $binding->capabilities ?? [];
                // Authorization is separate from the firmware gate enforced in both services.
                if (in_array('upsert_user', $caps, true) && in_array('get_option', $caps, true) && ! in_array('set_user_validity', $caps, true)) {
                    $binding->update(['capabilities' => [...$caps, 'set_user_validity']]);
                }
            }
        });
    }

    public function down(): void
    {
        // Keep authorization for already queued work during coordinated upgrades.
    }
};
