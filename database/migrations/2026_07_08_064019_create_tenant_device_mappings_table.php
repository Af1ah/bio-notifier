<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('tenant_device_mappings', function (Blueprint $table) {
            $table->string('serial_number')->primary();
            $table->string('organisation_id');
            $table->foreign('organisation_id')->references('id')->on('organisations')->cascadeOnDelete();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('tenant_device_mappings');
    }
};
