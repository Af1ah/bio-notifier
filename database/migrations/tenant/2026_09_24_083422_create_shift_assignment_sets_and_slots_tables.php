<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('shift_assignment_sets', function (Blueprint $table) {
            $table->id();
            $table->string('mode')->default('single');
            $table->foreignId('daily_policy_rule_revision_id')->nullable()->constrained('shift_rule_revisions')->nullOnDelete();
            $table->date('effective_from');
            $table->date('effective_to')->nullable();
            $table->boolean('active')->default(true);
            $table->timestamps();
            $table->index(['active', 'effective_from', 'effective_to']);
        });

        Schema::create('shift_assignment_slots', function (Blueprint $table) {
            $table->id();
            $table->foreignId('shift_assignment_set_id')->constrained()->cascadeOnDelete();
            $table->foreignId('schedule_id')->constrained()->restrictOnDelete();
            $table->foreignId('shift_rule_revision_id')->nullable()->constrained()->nullOnDelete();
            $table->unsignedSmallInteger('position')->default(1);
            $table->time('punch_window_boundary')->nullable();
            $table->unsignedTinyInteger('punch_window_boundary_day_offset')->default(0);
            $table->timestamps();
            $table->unique(['shift_assignment_set_id', 'position']);
            $table->unique(['shift_assignment_set_id', 'schedule_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('shift_assignment_slots');
        Schema::dropIfExists('shift_assignment_sets');
    }
};
