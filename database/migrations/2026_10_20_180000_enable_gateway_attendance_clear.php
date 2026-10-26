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
                if (in_array('upsert_user', $caps, true) && in_array('get_option', $caps, true) && ! in_array('clear_logs', $caps, true)) {
                    $binding->update(['capabilities' => [...$caps, 'clear_logs']]);
                }
            }
        });
    }

    public function down(): void
    {
        // Retain capability while queued commands are reconciled during a paired gateway rollback.
    }
};
