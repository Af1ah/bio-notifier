<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('gateway_applied_receipts', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->timestamp('applied_at');
        });
        Schema::create('gateway_attendance_evidence', function (Blueprint $t) {
            $t->id();
            $t->uuid('receipt_id');
            $t->unsignedInteger('record_index');
            $t->unsignedBigInteger('attendance_log_id');
            $t->foreign('attendance_log_id')->references('id')->on('attendance_logs')->restrictOnDelete();
            $t->string('serial', 64);
            $t->string('pin', 64);
            $t->string('original_time', 64);
            $t->unsignedSmallInteger('raw_status');
            $t->unsignedSmallInteger('raw_verify');
            $t->timestamps();
            $t->unique(['receipt_id', 'record_index']);
        });
        Schema::create('gateway_device_users', function (Blueprint $t) {
            $t->id();
            $t->foreignId('device_id')->constrained('devices')->cascadeOnDelete();
            $t->string('pin', 64);
            $t->string('name')->nullable();
            $t->string('card', 64)->nullable();
            $t->uuid('receipt_id');
            $t->timestamps();
            $t->unique(['device_id', 'pin']);
        });
        Schema::create('gateway_domain_effects', function (Blueprint $t) {
            $t->id();
            $t->uuid('receipt_id');
            $t->unsignedBigInteger('attendance_log_id');
            $t->string('type', 32)->default('calculation');
            $t->string('state')->default('pending');
            $t->uuid('lease_token')->nullable();
            $t->timestamp('leased_until')->nullable();
            $t->string('error_code')->nullable();
            $t->unsignedInteger('attempts')->default(0);
            $t->timestamps();
            $t->unique(['receipt_id', 'attendance_log_id', 'type']);
        });
        Schema::create('gateway_command_outbox', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->foreignId('projection_id')->constrained('device_commands')->restrictOnDelete();
            $t->unsignedBigInteger('binding_id');
            $t->unsignedInteger('binding_version');
            $t->string('operation', 32);
            $t->timestamp('expires_at');
            $t->timestamp('relayed_at')->nullable();
            $t->timestamps();
        });
        if (DB::getDriverName() === 'pgsql') {
            DB::statement('ALTER TABLE device_commands DROP CONSTRAINT IF EXISTS device_commands_status_check');
        }
        Schema::table('device_commands', function (Blueprint $t) {
            $t->string('status', 32)->default('pending')->change();
            $t->uuid('gateway_command_id')->nullable()->unique();
            $t->timestamp('gateway_result_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('device_commands', fn (Blueprint $t) => $t->dropColumn(['gateway_command_id', 'gateway_result_at']));
        foreach (['gateway_command_outbox', 'gateway_domain_effects', 'gateway_device_users', 'gateway_attendance_evidence', 'gateway_applied_receipts'] as $table) {
            Schema::dropIfExists($table);
        }
    }
};
