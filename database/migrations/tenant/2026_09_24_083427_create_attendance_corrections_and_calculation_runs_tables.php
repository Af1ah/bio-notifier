<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('attendance_corrections', function (Blueprint $t) {
            $t->id();
            $t->foreignId('attendance_day_id')->constrained()->cascadeOnDelete();
            $t->foreignId('attendance_occurrence_id')->nullable()->constrained()->nullOnDelete();
            $t->foreignId('created_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $t->foreignId('supersedes_id')->nullable()->constrained('attendance_corrections')->nullOnDelete();
            $t->timestamp('proposed_in_at')->nullable();
            $t->timestamp('proposed_out_at')->nullable();
            $t->string('proposed_status')->nullable();
            $t->text('reason');
            $t->string('state')->default('pending');
            $t->timestamps();
            $t->index(['attendance_day_id', 'state']);
        });
        Schema::create('attendance_calculation_runs', function (Blueprint $t) {
            $t->id();
            $t->uuid('run_key')->unique();
            $t->date('from_date');
            $t->date('to_date');
            $t->json('filters')->nullable();
            $t->string('state')->default('queued');
            $t->unsignedInteger('total')->default(0);
            $t->unsignedInteger('completed')->default(0);
            $t->unsignedInteger('failed')->default(0);
            $t->text('last_error')->nullable();
            $t->timestamps();
            $t->index(['state', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('attendance_calculation_runs');
        Schema::dropIfExists('attendance_corrections');
    }
};
