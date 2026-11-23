<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('gateway_device_users', function (Blueprint $table) {
            $table->text('access_settings')->nullable();
            $table->timestamp('access_received_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('gateway_device_users', fn (Blueprint $table) => $table->dropColumn(['access_settings', 'access_received_at']));
    }
};
