<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('organisation_backup_settings', function (Blueprint $table) {
            $table->string('organisation_id')->primary();
            $table->uuid('connection_id')->nullable();
            $table->string('drive_account')->nullable();
            $table->string('drive_name')->nullable();
            $table->text('refresh_token')->nullable();
            $table->string('frequency', 10)->nullable();
            $table->string('backup_time', 5);
            $table->string('timezone', 64);
            $table->timestamp('next_run_at')->nullable()->index();
            $table->foreign('organisation_id')->references('id')->on('organisations')->cascadeOnDelete();
        });

        Schema::create('organisation_backup_runs', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('organisation_id');
            $table->uuid('connection_id');
            $table->string('drive_file_id')->nullable();
            $table->string('status', 16);
            $table->unsignedSmallInteger('attempts')->default(0);
            $table->string('error', 32)->nullable();
            $table->string('checksum', 32)->nullable();
            $table->unsignedBigInteger('bytes')->nullable();
            $table->timestamp('available_at')->nullable()->index();
            $table->timestamp('verified_at')->nullable();
            $table->timestamps();
            $table->index(['organisation_id', 'created_at']);
            $table->foreign('organisation_id')->references('id')->on('organisations')->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('organisation_backup_runs');
        Schema::dropIfExists('organisation_backup_settings');
    }
};
