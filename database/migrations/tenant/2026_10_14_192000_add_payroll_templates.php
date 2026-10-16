<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('payroll_templates', function (Blueprint $t) {
            $t->id();
            $t->string('name', 120);
            $t->foreignId('branch_id')->nullable()->constrained()->restrictOnDelete();
            $t->string('role')->nullable();
            $t->date('effective_from');
            $t->json('settings');
            $t->timestamps();
        });
        Schema::table('employee_payroll_profiles', function (Blueprint $t) {
            $t->foreignId('payroll_template_id')->nullable()->constrained()->restrictOnDelete();
            $t->json('template_snapshot')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('employee_payroll_profiles', function (Blueprint $t) {
            $t->dropForeign(['payroll_template_id']);
            $t->dropColumn(['payroll_template_id', 'template_snapshot']);
        });
        Schema::dropIfExists('payroll_templates');
    }
};
