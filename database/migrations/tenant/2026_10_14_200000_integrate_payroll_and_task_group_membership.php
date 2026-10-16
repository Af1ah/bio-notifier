<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', fn (Blueprint $t) => $t->string('religion', 80)->nullable()->after('group'));
        Schema::table('employee_payroll_profiles', fn (Blueprint $t) => $t->json('template_overrides')->nullable()->after('template_snapshot'));
        Schema::create('payroll_profile_revisions', function (Blueprint $t) {
            $t->id(); $t->foreignId('employee_payroll_profile_id')->constrained()->restrictOnDelete();
            $t->date('effective_from'); $t->json('snapshot'); $t->foreignId('changed_by_user_id')->nullable()->constrained('users')->nullOnDelete(); $t->timestamps();
            $t->unique(['employee_payroll_profile_id', 'effective_from']);
        });
        Schema::create('task_group_memberships', function (Blueprint $t) {
            $t->id(); $t->foreignId('task_group_id')->constrained()->restrictOnDelete(); $t->foreignId('user_id')->constrained()->restrictOnDelete();
            $t->date('starts_on'); $t->date('ends_on')->nullable(); $t->foreignId('created_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $t->foreignId('ended_by_user_id')->nullable()->constrained('users')->nullOnDelete(); $t->string('end_reason')->nullable(); $t->timestamps();
            $t->index(['user_id', 'task_group_id', 'starts_on']);
        });
    }
    public function down(): void
    {
        Schema::dropIfExists('task_group_memberships'); Schema::dropIfExists('payroll_profile_revisions');
        Schema::table('employee_payroll_profiles', fn (Blueprint $t) => $t->dropColumn('template_overrides'));
        Schema::table('users', fn (Blueprint $t) => $t->dropColumn('religion'));
    }
};
