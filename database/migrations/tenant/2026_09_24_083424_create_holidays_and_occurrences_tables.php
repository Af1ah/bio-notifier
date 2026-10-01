<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('holidays', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->date('starts_on');
            $table->date('ends_on');
            $table->string('recurrence')->default('none');
            $table->unsignedSmallInteger('start_year')->nullable();
            $table->unsignedSmallInteger('end_year')->nullable();
            $table->boolean('active')->default(true);
            $table->timestamps();
            $table->index(['active', 'starts_on', 'ends_on']);
        });

        Schema::create('holiday_occurrences', function (Blueprint $table) {
            $table->id();
            $table->foreignId('holiday_id')->constrained()->cascadeOnDelete();
            $table->date('holiday_date');
            $table->timestamps();
            $table->unique(['holiday_id', 'holiday_date']);
            $table->index('holiday_date');
        });

        Schema::create('holiday_branch_coverage', function (Blueprint $table) {
            $table->id();
            $table->foreignId('holiday_id')->constrained()->cascadeOnDelete();
            $table->foreignId('branch_id')->constrained()->cascadeOnDelete();
            $table->unique(['holiday_id', 'branch_id']);
        });

        Schema::create('holiday_department_coverage', function (Blueprint $table) {
            $table->id();
            $table->foreignId('holiday_id')->constrained()->cascadeOnDelete();
            $table->foreignId('department_id')->constrained()->cascadeOnDelete();
            $table->unique(['holiday_id', 'department_id']);
        });

        Schema::create('holiday_task_group_coverage', function (Blueprint $table) {
            $table->id();
            $table->foreignId('holiday_id')->constrained()->cascadeOnDelete();
            $table->foreignId('task_group_id')->constrained()->cascadeOnDelete();
            $table->unique(['holiday_id', 'task_group_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('holiday_task_group_coverage');
        Schema::dropIfExists('holiday_department_coverage');
        Schema::dropIfExists('holiday_branch_coverage');
        Schema::dropIfExists('holiday_occurrences');
        Schema::dropIfExists('holidays');
    }
};
