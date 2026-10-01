<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('device_commands', function (Blueprint $table) {
            $table->foreignId('device_id')->nullable()->change();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        DB::table('device_commands')->whereNull('device_id')->delete();

        Schema::table('device_commands', function (Blueprint $table) {
            $table->foreignId('device_id')->nullable(false)->change();
        });
    }
};
