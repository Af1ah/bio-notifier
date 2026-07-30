<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('attendance_logs', function (Blueprint $table) {
            // Remove potential duplicates first, keeping the first created one
            \Illuminate\Support\Facades\DB::statement('
                DELETE FROM attendance_logs a USING (
                    SELECT MIN(id) as id, pin, punched_at
                    FROM attendance_logs
                    GROUP BY pin, punched_at
                    HAVING COUNT(*) > 1
                ) b
                WHERE a.pin = b.pin AND a.punched_at = b.punched_at AND a.id <> b.id
            ');

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
