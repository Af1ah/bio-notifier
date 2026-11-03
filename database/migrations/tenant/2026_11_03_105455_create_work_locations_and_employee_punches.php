<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('work_locations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('branch_id')->nullable()->constrained()->nullOnDelete();
            $table->string('name');
            $table->decimal('latitude', 10, 7);
            $table->decimal('longitude', 10, 7);
            $table->unsignedInteger('radius_meters')->default(100);
            $table->boolean('is_active')->default(true)->index();
            $table->timestamps();
        });
        Schema::table('attendance_logs', function (Blueprint $table) {
            $table->foreignId('device_id')->nullable()->change();
            $table->foreignId('portal_user_id')->nullable()->constrained('users')->restrictOnDelete();
            $table->uuid('portal_request_id')->nullable();
            $table->unique(['portal_user_id', 'portal_request_id']);
        });
        $this->preserveSqliteTriggers();
    }

    public function down(): void
    {
        // Keep recorded portal punches and their owning users; roll back only before use.
        if (DB::table('attendance_logs')->whereNotNull('portal_user_id')->exists()) {
            throw new RuntimeException('Portal punch evidence exists. Preserve it before rolling back.');
        }
        Schema::table('attendance_logs', function (Blueprint $table) {
            $table->dropUnique(['portal_user_id', 'portal_request_id']);
            $table->dropConstrainedForeignId('portal_user_id');
            $table->dropColumn('portal_request_id');
            $table->foreignId('device_id')->nullable(false)->change();
        });
        $this->preserveSqliteTriggers();
        Schema::dropIfExists('work_locations');
    }

    private function preserveSqliteTriggers(): void
    {
        // SQLite rebuilds the table for column changes and drops its triggers.
        if (DB::getDriverName() === 'sqlite') {
            DB::unprepared("CREATE TRIGGER IF NOT EXISTS attendance_logs_no_update BEFORE UPDATE ON attendance_logs BEGIN SELECT RAISE(ABORT, 'Raw attendance punches are immutable'); END");
            DB::unprepared("CREATE TRIGGER IF NOT EXISTS attendance_logs_no_delete BEFORE DELETE ON attendance_logs BEGIN SELECT RAISE(ABORT, 'Raw attendance punches are immutable'); END");
        }
    }
};
