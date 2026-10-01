<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('attendance_days', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->date('work_date');
            $table->string('status')->default('no_shift');
            $table->unsignedInteger('candidate_worked_minutes')->default(0);
            $table->unsignedInteger('approved_worked_minutes')->default(0);
            $table->unsignedInteger('candidate_overtime_minutes')->default(0);
            $table->unsignedInteger('approved_overtime_minutes')->default(0);
            $table->unsignedInteger('calculation_revision')->default(1);
            $table->timestamp('calculated_at')->nullable();
            $table->timestamp('stale_at')->nullable();
            $table->json('explanation')->nullable();
            $table->timestamps();
            $table->unique(['user_id', 'work_date']);
            $table->index(['work_date', 'status']);
        });

        Schema::create('attendance_occurrences', function (Blueprint $table) {
            $table->id();
            $table->foreignId('attendance_day_id')->constrained()->cascadeOnDelete();
            $table->foreignId('shift_assignment_slot_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('schedule_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('shift_rule_revision_id')->nullable()->constrained()->nullOnDelete();
            $table->unsignedSmallInteger('position')->default(1);
            $table->timestamp('window_starts_at');
            $table->timestamp('window_ends_at');
            $table->timestamp('first_in_at')->nullable();
            $table->timestamp('last_out_at')->nullable();
            $table->timestamp('assumed_out_at')->nullable();
            $table->unsignedInteger('candidate_worked_minutes')->default(0);
            $table->unsignedInteger('approved_worked_minutes')->default(0);
            $table->unsignedInteger('candidate_overtime_minutes')->default(0);
            $table->unsignedInteger('approved_overtime_minutes')->default(0);
            $table->unsignedInteger('late_minutes')->default(0);
            $table->unsignedInteger('early_minutes')->default(0);
            $table->string('status')->default('pending');
            $table->json('exception_flags')->nullable();
            $table->json('explanation')->nullable();
            $table->timestamps();
            $table->unique(['attendance_day_id', 'position']);
            $table->index(['schedule_id', 'window_starts_at']);
        });

        Schema::create('attendance_occurrence_punches', function (Blueprint $table) {
            $table->id();
            $table->foreignId('attendance_occurrence_id')->constrained()->cascadeOnDelete();
            $table->foreignId('attendance_log_id')->constrained()->restrictOnDelete();
            $table->string('role');
            $table->timestamps();
            $table->unique(['attendance_occurrence_id', 'attendance_log_id']);
            $table->index(['attendance_log_id', 'role']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('attendance_occurrence_punches');
        Schema::dropIfExists('attendance_occurrences');
        Schema::dropIfExists('attendance_days');
    }
};
