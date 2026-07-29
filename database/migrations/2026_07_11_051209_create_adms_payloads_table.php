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
        Schema::create('adms_payloads', function (Blueprint $table) {
            $table->id();
            $table->string('serial_number');
            $table->string('tenant_id')->nullable();
            $table->string('table_name')->nullable();
            $table->string('stamp')->nullable();
            $table->longText('payload');
            $table->enum('status', ['pending', 'failed'])->default('pending');
            $table->text('error_message')->nullable();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('adms_payloads');
    }
};
