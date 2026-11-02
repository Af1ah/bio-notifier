<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('leave_balance_revisions', function (Blueprint $table): void {
            $table->string('scope')->primary();
            $table->unsignedBigInteger('revision')->default(0);
        });
        Schema::create('leave_balance_summaries', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('leave_type_id')->constrained()->cascadeOnDelete();
            $table->date('as_of');
            $table->unsignedBigInteger('global_revision');
            $table->unsignedBigInteger('employee_revision');
            $table->json('balances');
            $table->timestamps();
            $table->unique(['user_id', 'leave_type_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('leave_balance_summaries');
        Schema::dropIfExists('leave_balance_revisions');
    }
};
