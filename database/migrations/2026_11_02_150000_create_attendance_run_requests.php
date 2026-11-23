<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('attendance_run_requests', function (Blueprint $table) {
            $table->id();
            $table->string('organisation_id');
            $table->uuid('run_key')->unique();
            $table->unsignedBigInteger('tenant_run_id')->nullable();
            $table->date('nightly_work_date')->nullable();
            $table->date('from_date');
            $table->date('to_date');
            $table->json('user_ids');
            $table->json('filters');
            $table->unsignedInteger('cursor')->default(0);
            $table->string('state')->default('queued')->index();
            $table->unsignedInteger('failures')->default(0);
            $table->text('last_error')->nullable();
            $table->timestamps();
            $table->unique(['organisation_id', 'nightly_work_date']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('attendance_run_requests');
    }
};
