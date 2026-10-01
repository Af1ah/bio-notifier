<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        if (DB::getDriverName() === 'pgsql') {
            DB::statement('DELETE FROM attendance_logs a USING attendance_logs b WHERE a.pin = b.pin AND a.punched_at = b.punched_at AND a.id > b.id');
        } else {
            DB::statement('DELETE FROM attendance_logs WHERE id NOT IN (SELECT id FROM (SELECT MIN(id) AS id FROM attendance_logs GROUP BY pin, punched_at) retained)');
        }

        Schema::table('attendance_logs', function (Blueprint $table) {
            $table->unique(['pin', 'punched_at'], 'unique_punch');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('attendance_logs', function (Blueprint $table) {
            $table->dropUnique('unique_punch');
        });
    }
};
