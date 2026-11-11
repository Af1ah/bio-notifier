<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('devices', fn (Blueprint $table) => $table->softDeletes());
    }

    public function down(): void
    {
        if (\Illuminate\Support\Facades\DB::table('devices')->whereNotNull('deleted_at')->exists()) {
            throw new RuntimeException('Removed devices exist. Restore them explicitly before rolling back.');
        }
        Schema::table('devices', fn (Blueprint $table) => $table->dropSoftDeletes());
    }
};
