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
        Schema::create('employee_payroll_profiles', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->unique()->constrained()->cascadeOnDelete();
            $table->boolean('payroll_enabled')->default(false);
            $table->char('currency', 3)->default('INR');
            $table->unsignedBigInteger('basic_salary_minor')->default(0);
            $table->unsignedBigInteger('housing_allowance_minor')->default(0);
            $table->unsignedBigInteger('transport_allowance_minor')->default(0);
            $table->unsignedBigInteger('other_allowance_minor')->default(0);
            $table->unsignedBigInteger('fixed_deduction_minor')->default(0);
            $table->unsignedBigInteger('overtime_hourly_rate_minor')->default(0);
            $table->date('effective_from')->nullable();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('employee_payroll_profiles');
    }
};
