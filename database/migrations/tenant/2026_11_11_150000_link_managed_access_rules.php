<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('device_access_settings', fn (Blueprint $table) => $table->foreignId('managed_period_id')->nullable()->constrained('device_access_settings')->restrictOnDelete());
    }

    public function down(): void
    {
        Schema::table('device_access_settings', fn (Blueprint $table) => $table->dropConstrainedForeignId('managed_period_id'));
    }
};
