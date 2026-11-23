<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('frappe_checkin_deliveries', function (Blueprint $table) {
            $table->foreignId('attendance_log_id')->primary()->constrained('attendance_logs')->cascadeOnDelete();
            $table->unsignedTinyInteger('attempts')->default(0);
            $table->timestamp('retry_at')->nullable()->index();
            $table->timestamp('sent_at')->nullable();
            $table->string('error', 40)->nullable();
        });

        Schema::create('frappe_settings', function (Blueprint $table) {
            $table->unsignedTinyInteger('id')->primary();
            $table->boolean('enabled')->default(false);
            $table->string('hostname');
            $table->text('api_key');
            $table->text('api_secret');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('frappe_settings');
        Schema::dropIfExists('frappe_checkin_deliveries');
    }
};
