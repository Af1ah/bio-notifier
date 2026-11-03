<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('employee_portal_settings', function (Blueprint $table) {
            $table->unsignedTinyInteger('id')->primary();
            $table->boolean('geofencing_enabled')->default(false);
            $table->timestamps();
        });
        DB::table('employee_portal_settings')->insert(['id' => 1,
            'geofencing_enabled' => DB::table('work_locations')->where('is_active', true)->exists(),
            'created_at' => now(), 'updated_at' => now()]);
    }

    public function down(): void
    {
        Schema::dropIfExists('employee_portal_settings');
    }
};
