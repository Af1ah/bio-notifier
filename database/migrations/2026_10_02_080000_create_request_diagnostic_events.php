<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('request_diagnostic_lock', function (Blueprint $table) {
            $table->unsignedInteger('id')->primary();
        });
        DB::table('request_diagnostic_lock')->insert(['id' => 1]);
        Schema::create('request_diagnostic_events', function (Blueprint $table) {
            $table->id();
            $table->string('method', 16);
            $table->string('route', 200);
            $table->unsignedSmallInteger('status');
            $table->timestamp('created_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('request_diagnostic_events');
        Schema::dropIfExists('request_diagnostic_lock');
    }
};
