<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('notifications', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('type');
            $table->morphs('notifiable');
            $table->json('data');
            $table->timestamp('read_at')->nullable();
            $table->timestamps();
        });
        foreach (['device_commands', 'attendance_calculation_runs'] as $name) {
            Schema::table($name, function (Blueprint $table) {
                $table->foreignId('requested_by_user_id')->nullable()->constrained('users')->nullOnDelete();
                $table->string('notification_outcome', 64)->nullable();
            });
        }
    }

    public function down(): void
    {
        foreach (['device_commands', 'attendance_calculation_runs'] as $name) {
            Schema::table($name, function (Blueprint $table) {
                $table->dropConstrainedForeignId('requested_by_user_id');
                $table->dropColumn('notification_outcome');
            });
        }
        Schema::dropIfExists('notifications');
    }
};
