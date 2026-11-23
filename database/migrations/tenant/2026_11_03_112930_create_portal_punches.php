<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('portal_punches', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->restrictOnDelete();
            $table->foreignId('work_location_id')->nullable()->constrained()->nullOnDelete();
            $table->uuid('request_id');
            $table->string('kind', 3);
            $table->timestamp('punched_at');
            $table->string('state')->default('pending')->index();
            $table->string('photo_path');
            $table->json('evidence');
            $table->foreignId('attendance_log_id')->nullable()->constrained()->restrictOnDelete();
            $table->foreignId('reviewed_by_user_id')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestamp('reviewed_at')->nullable();
            $table->text('review_note')->nullable();
            $table->timestamps();
            $table->unique(['user_id', 'request_id']);
            $table->index(['user_id', 'punched_at']);
        });
    }

    public function down(): void
    {
        if (DB::table('portal_punches')->exists()) {
            throw new RuntimeException('Punch requests exist. Preserve their photos and review history before rollback.');
        }
        Schema::dropIfExists('portal_punches');
    }
};
