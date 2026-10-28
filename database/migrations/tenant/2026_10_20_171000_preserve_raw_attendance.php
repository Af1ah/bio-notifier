<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        if (DB::getDriverName() === 'pgsql') {
            DB::unprepared("CREATE OR REPLACE FUNCTION preserve_raw_attendance() RETURNS trigger LANGUAGE plpgsql AS $$ BEGIN RAISE EXCEPTION 'Raw device punches are immutable; use manual corrections'; END $$;");
            DB::unprepared('CREATE TRIGGER attendance_logs_immutable BEFORE UPDATE OR DELETE ON attendance_logs FOR EACH ROW EXECUTE FUNCTION preserve_raw_attendance()');
            DB::unprepared('CREATE TRIGGER attendance_logs_no_truncate BEFORE TRUNCATE ON attendance_logs FOR EACH STATEMENT EXECUTE FUNCTION preserve_raw_attendance()');
        } elseif (DB::getDriverName() === 'sqlite') {
            DB::unprepared("CREATE TRIGGER attendance_logs_no_update BEFORE UPDATE ON attendance_logs BEGIN SELECT RAISE(ABORT, 'Raw device punches are immutable'); END");
            DB::unprepared("CREATE TRIGGER attendance_logs_no_delete BEFORE DELETE ON attendance_logs BEGIN SELECT RAISE(ABORT, 'Raw device punches are immutable'); END");
        }
    }

    public function down(): void
    {
        if (DB::getDriverName() === 'pgsql') {
            DB::unprepared('DROP TRIGGER IF EXISTS attendance_logs_immutable ON attendance_logs');
            DB::unprepared('DROP TRIGGER IF EXISTS attendance_logs_no_truncate ON attendance_logs');
            DB::unprepared('DROP FUNCTION IF EXISTS preserve_raw_attendance()');
        } elseif (DB::getDriverName() === 'sqlite') {
            DB::unprepared('DROP TRIGGER IF EXISTS attendance_logs_no_update');
            DB::unprepared('DROP TRIGGER IF EXISTS attendance_logs_no_delete');
        }
    }
};
