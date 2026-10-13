<?php

use App\Services\Devices\DeviceIntegration;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('organisations', function (Blueprint $table) {
            $table->string('device_integration', 32)->default(DeviceIntegration::EBIO);
        });

        // Existing companies retain their working integration.
        DB::table('organisations')->update(['device_integration' => DeviceIntegration::EBIO]);

        Schema::table('organisations', function (Blueprint $table) {
            $table->string('device_integration', 32)->default(DeviceIntegration::GATEWAY)->change();
        });
    }

    public function down(): void
    {
        Schema::table('organisations', function (Blueprint $table) {
            $table->dropColumn('device_integration');
        });
    }
};
