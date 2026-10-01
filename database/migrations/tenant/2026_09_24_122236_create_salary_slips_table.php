<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('salary_slips', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->restrictOnDelete();
            $table->date('period_start');
            $table->date('period_end');
            $table->string('status')->default('draft');
            $table->string('designation')->nullable();
            $table->char('currency', 3)->default('INR');
            $table->unsignedBigInteger('basic_salary_minor')->default(0);
            $table->unsignedBigInteger('housing_allowance_minor')->default(0);
            $table->unsignedBigInteger('transport_allowance_minor')->default(0);
            $table->unsignedBigInteger('other_allowance_minor')->default(0);
            $table->unsignedBigInteger('overtime_pay_minor')->default(0);
            $table->unsignedBigInteger('gross_pay_minor')->default(0);
            $table->unsignedBigInteger('attendance_deduction_minor')->default(0);
            $table->unsignedBigInteger('leave_deduction_minor')->default(0);
            $table->unsignedBigInteger('fixed_deduction_minor')->default(0);
            $table->unsignedBigInteger('total_deduction_minor')->default(0);
            $table->unsignedBigInteger('net_pay_minor')->default(0);
            $table->unsignedInteger('scheduled_days')->default(0);
            $table->unsignedInteger('full_days')->default(0);
            $table->unsignedInteger('half_days')->default(0);
            $table->unsignedInteger('absent_days')->default(0);
            $table->unsignedInteger('paid_leave_half_units')->default(0);
            $table->unsignedInteger('unpaid_leave_half_units')->default(0);
            $table->unsignedInteger('approved_overtime_minutes')->default(0);
            $table->json('calculation_snapshot');
            $table->unsignedInteger('calculation_version')->default(1);
            $table->timestamp('calculated_at');
            $table->foreignId('reviewed_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('approved_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('paid_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('reviewed_at')->nullable();
            $table->timestamp('approved_at')->nullable();
            $table->timestamp('paid_at')->nullable();
            $table->timestamps();
            $table->unique(['user_id', 'period_start', 'period_end']);
            $table->index(['period_start', 'status']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('salary_slips');
    }
};
