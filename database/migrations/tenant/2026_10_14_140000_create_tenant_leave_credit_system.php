<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tenant_leave_settings', function (Blueprint $t) {
            $t->unsignedInteger('id')->primary();
            $t->string('timezone');
            $t->unsignedInteger('expiry_warning_days')->default(30);
            $t->foreignId('updated_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $t->timestamp('updated_at')->nullable();
        });
        Schema::create('leave_policies', function (Blueprint $t) {
            $t->id();
            $t->foreignId('leave_type_id')->constrained()->restrictOnDelete();
            $t->string('name');
            $t->boolean('tenant_default')->default(false);
            $t->timestamps();
        });
        Schema::create('leave_policy_revisions', function (Blueprint $t) {
            $t->id();
            $t->foreignId('leave_policy_id')->constrained()->restrictOnDelete();
            $t->date('effective_from');
            $t->json('rules');
            $t->text('reason');
            $t->foreignId('created_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $t->timestamps();
            $t->unique(['leave_policy_id', 'effective_from']);
        });
        Schema::create('employee_leave_policy_assignments', function (Blueprint $t) {
            $t->id();
            $t->foreignId('user_id')->constrained()->restrictOnDelete();
            $t->foreignId('leave_policy_id')->constrained()->restrictOnDelete();
            $t->date('starts_on');
            $t->date('ends_on')->nullable();
            $t->json('changes')->nullable();
            $t->foreignId('created_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $t->timestamps();
            $t->index(['user_id', 'starts_on']);
        });
        Schema::create('leave_credit_lots', function (Blueprint $t) {
            $t->id();
            $t->foreignId('user_id')->constrained()->restrictOnDelete();
            $t->foreignId('leave_type_id')->constrained()->restrictOnDelete();
            $t->foreignId('leave_policy_revision_id')->nullable()->constrained()->restrictOnDelete();
            $t->unsignedInteger('granted_half_units');
            $t->date('available_on');
            $t->date('expires_on');
            $t->string('source_kind');
            $t->string('source_key')->unique();
            $t->json('evidence');
            $t->timestamps();
            $t->index(['user_id', 'leave_type_id', 'expires_on']);
        });
        Schema::create('leave_ledger_entries', function (Blueprint $t) {
            $t->id();
            $t->foreignId('leave_credit_lot_id')->constrained()->restrictOnDelete();
            $t->string('kind');
            $t->integer('half_units');
            $t->date('effective_on');
            $t->string('idempotency_key')->unique();
            $t->text('reason');
            $t->foreignId('actor_id')->nullable()->constrained('users')->nullOnDelete();
            $t->timestamps();
        });
        Schema::create('leave_allocations', function (Blueprint $t) {
            $t->id();
            $t->foreignId('employee_leave_id')->constrained()->restrictOnDelete();
            $t->foreignId('leave_credit_lot_id')->constrained()->restrictOnDelete();
            $t->unsignedInteger('half_units');
            $t->string('state');
            $t->timestamps();
        });
        Schema::create('compensatory_awards', function (Blueprint $t) {
            $t->id();
            $t->foreignId('user_id')->constrained()->restrictOnDelete();
            $t->date('work_date');
            $t->foreignId('attendance_day_id')->constrained()->restrictOnDelete();
            $t->foreignId('leave_policy_revision_id')->constrained()->restrictOnDelete();
            $t->foreignId('leave_credit_lot_id')->nullable()->constrained()->restrictOnDelete();
            $t->string('reward');
            $t->string('state');
            $t->unsignedInteger('half_units');
            $t->unsignedBigInteger('payment_minor')->default(0);
            $t->string('evidence_signature', 64);
            $t->json('evidence');
            $t->text('reason');
            $t->foreignId('reviewed_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $t->timestamps();
            $t->unique(['user_id', 'work_date']);
        });
        Schema::table('employee_leaves', function (Blueprint $t) {
            $t->foreignId('reviewed_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $t->timestamp('reviewed_at')->nullable();
            $t->boolean('balance_managed')->default(false);
        });
    }

    public function down(): void
    {
        Schema::table('employee_leaves', function (Blueprint $t) {
            $t->dropForeign(['reviewed_by_user_id']);
            $t->dropColumn(['reviewed_by_user_id', 'reviewed_at', 'balance_managed']);
        });
        foreach (['compensatory_awards', 'leave_allocations', 'leave_ledger_entries', 'leave_credit_lots', 'employee_leave_policy_assignments', 'leave_policy_revisions', 'leave_policies', 'tenant_leave_settings'] as $table) {
            Schema::dropIfExists($table);
        }
    }
};
