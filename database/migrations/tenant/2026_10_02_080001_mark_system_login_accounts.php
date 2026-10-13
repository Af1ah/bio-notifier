<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', fn (Blueprint $table) => $table->boolean('is_system_account')->default(false)->index());
        DB::table('users')->where('email', 'admin@zkteco.local')->update(['is_system_account' => true]);
    }

    public function down(): void
    {
        Schema::table('users', fn (Blueprint $table) => $table->dropColumn('is_system_account'));
    }
};
