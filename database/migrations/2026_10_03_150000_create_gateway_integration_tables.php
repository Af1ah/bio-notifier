<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('gateway_connections', function (Blueprint $t) {
            $t->id();
            $t->string('name')->default('Primary gateway');
            $t->string('management_url');
            $t->text('management_token');
            $t->text('events_token');
            $t->boolean('enabled')->default(false);
            $t->timestamp('last_healthy_at')->nullable();
            $t->timestamps();
        });
        Schema::create('gateway_device_bindings', function (Blueprint $t) {
            $t->id();
            $t->foreignId('gateway_connection_id')->constrained('gateway_connections')->restrictOnDelete();
            $t->string('organisation_id');
            $t->foreign('organisation_id')->references('id')->on('organisations')->restrictOnDelete();
            $t->unsignedBigInteger('device_id');
            $t->string('serial', 64)->unique();
            $t->unsignedInteger('version')->default(1);
            $t->boolean('enabled')->default(true);
            $t->string('source_cidr', 64);
            $t->string('timezone')->default('Asia/Kolkata');
            $t->json('capabilities');
            $t->timestamp('synced_at')->nullable();
            $t->string('last_error')->nullable();
            $t->timestamps();
            $t->unique(['organisation_id', 'device_id']);
        });
        Schema::create('gateway_binding_versions', function (Blueprint $t) {
            $t->id();
            $t->foreignId('binding_id')->constrained('gateway_device_bindings')->restrictOnDelete();
            $t->unsignedInteger('version');
            $t->string('organisation_id');
            $t->unsignedBigInteger('device_id');
            $t->foreignId('gateway_connection_id')->constrained('gateway_connections')->restrictOnDelete();
            $t->string('source_cidr', 64);
            $t->timestamp('activated_at')->nullable();
            $t->timestamps();
            $t->unique(['binding_id', 'version']);
        });
        Schema::create('gateway_ingress_receipts', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->foreignId('gateway_connection_id')->constrained('gateway_connections')->restrictOnDelete();
            $t->foreignId('binding_id')->constrained('gateway_device_bindings')->restrictOnDelete();
            $t->string('organisation_id');
            $t->unsignedBigInteger('device_id');
            $t->unsignedInteger('binding_version');
            $t->string('kind', 32);
            $t->string('payload_hash', 64);
            $t->text('payload');
            $t->string('state', 32)->default('pending');
            $t->unsignedInteger('attempts')->default(0);
            $t->uuid('lease_token')->nullable();
            $t->timestamp('leased_until')->nullable();
            $t->timestamp('next_attempt_at')->nullable();
            $t->string('error_code')->nullable();
            $t->timestamps();
            $t->index(['state', 'next_attempt_at']);
        });
        Schema::create('gateway_command_submissions', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->foreignId('binding_id')->constrained('gateway_device_bindings')->restrictOnDelete();
            $t->string('organisation_id');
            $t->unsignedBigInteger('projection_id');
            $t->unsignedInteger('binding_version');
            $t->string('operation', 32);
            $t->timestamp('expires_at');
            $t->string('state', 32)->default('pending');
            $t->unsignedInteger('attempts')->default(0);
            $t->uuid('lease_token')->nullable();
            $t->timestamp('leased_until')->nullable();
            $t->timestamp('next_attempt_at')->nullable();
            $t->string('error_code')->nullable();
            $t->timestamps();
            $t->index(['state', 'next_attempt_at']);
        });
        Schema::create('gateway_audit_events', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('admin_id')->nullable();
            $t->string('action');
            $t->string('subject');
            $t->json('metadata')->nullable();
            $t->timestamps();
        });
    }

    public function down(): void
    {
        foreach (['gateway_audit_events', 'gateway_command_submissions', 'gateway_ingress_receipts', 'gateway_binding_versions', 'gateway_device_bindings', 'gateway_connections'] as $table) {
            Schema::dropIfExists($table);
        }
    }
};
