<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('attendance_approvals', function (Blueprint $table) {
            $table->id();
            $table->foreignId('attendance_day_id')->constrained()->cascadeOnDelete();
            $table->foreignId('attendance_occurrence_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('requested_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('decided_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('type');
            $table->string('state')->default('pending');
            $table->unsignedInteger('calculation_revision');
            $table->string('idempotency_key')->nullable();
            $table->json('proposal');
            $table->text('decision_reason')->nullable();
            $table->timestamp('decided_at')->nullable();
            $table->timestamp('superseded_at')->nullable();
            $table->timestamps();
            $table->index(['type', 'state', 'created_at']);
            $table->index(['attendance_day_id', 'calculation_revision']);
            $table->unique(['idempotency_key']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('attendance_approvals');
    }
};
