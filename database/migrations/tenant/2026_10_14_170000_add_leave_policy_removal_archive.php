<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('leave_policy_removals', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('original_policy_id')->unique();
            $table->string('policy_name');
            $table->foreignId('removed_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->text('reason');
            $table->json('snapshot');
            $table->timestamp('removed_at');
        });
        Schema::table('leave_policy_revisions', function (Blueprint $table) {
            $table->unsignedBigInteger('leave_policy_id')->nullable()->change();
        });
    }

    public function down(): void
    {
        if (DB::table('leave_policy_removals')->exists()) {
            throw new RuntimeException('Retained payroll removal history requires explicit recovery before rolling back this migration.');
        }
        Schema::table('leave_policy_revisions', function (Blueprint $table) {
            $table->unsignedBigInteger('leave_policy_id')->nullable(false)->change();
        });
        Schema::dropIfExists('leave_policy_removals');
    }
};
