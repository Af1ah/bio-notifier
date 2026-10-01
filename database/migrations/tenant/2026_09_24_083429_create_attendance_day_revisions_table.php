<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('attendance_day_revisions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('attendance_day_id')->constrained()->cascadeOnDelete();
            $table->unsignedInteger('revision');
            $table->json('snapshot');
            $table->boolean('had_approved_attendance')->default(false);
            $table->boolean('had_approved_overtime')->default(false);
            $table->timestamp('superseded_at');
            $table->timestamps();
            $table->unique(['attendance_day_id', 'revision']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('attendance_day_revisions');
    }
};
