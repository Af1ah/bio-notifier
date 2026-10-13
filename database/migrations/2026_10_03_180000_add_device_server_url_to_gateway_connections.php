<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('gateway_connections', fn (Blueprint $table) => $table->string('device_server_url')->nullable());
    }

    public function down(): void
    {
        Schema::table('gateway_connections', fn (Blueprint $table) => $table->dropColumn('device_server_url'));
    }
};
