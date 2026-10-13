<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('gateway_runtime_states', function (Blueprint $table) {
            $table->string('id')->primary();
            $table->string('role');
            $table->string('organisation_id')->nullable();
            $table->json('details')->nullable();
            $table->timestamp('heartbeat_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('gateway_runtime_states');
    }
};
