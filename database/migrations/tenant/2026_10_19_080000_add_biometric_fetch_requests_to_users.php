<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('users', 'biometric_fetch_requests')) {
            Schema::table('users', fn (Blueprint $table) => $table->json('biometric_fetch_requests')->nullable());
        }
    }

    public function down(): void
    {
        Schema::table('users', fn (Blueprint $table) => $table->dropColumn('biometric_fetch_requests'));
    }
};
