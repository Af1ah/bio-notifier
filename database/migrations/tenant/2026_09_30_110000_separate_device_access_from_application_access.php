<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('users', 'device_privilege')) {
            Schema::table('users', function (Blueprint $table) {
                $table->unsignedTinyInteger('device_privilege')->default(0)->after('privilege');
            });
        }

        if (! Schema::hasColumn('users', 'device_verification_type')) {
            Schema::table('users', function (Blueprint $table) {
                $table->string('device_verification_type')->nullable()->after('device_privilege');
            });
        }

        if (! Schema::hasColumn('users', 'face_enrolled')) {
            Schema::table('users', function (Blueprint $table) {
                $table->boolean('face_enrolled')->nullable()->after('face_templates');
            });
        }

        // Preserve the device role that was historically stored in privilege.
        DB::table('users')->update([
            'device_privilege' => DB::raw('privilege'),
        ]);

        // PostgreSQL's json type has no generic inequality operator. Decode the
        // small legacy value in PHP so this backfill also works on SQLite/MySQL.
        DB::table('users')
            ->select(['id', 'face_templates'])
            ->whereNotNull('face_templates')
            ->orderBy('id')
            ->chunkById(200, function ($users): void {
                foreach ($users as $user) {
                    $templates = json_decode((string) $user->face_templates, true);

                    if (is_array($templates) && $templates !== []) {
                        DB::table('users')
                            ->where('id', $user->id)
                            ->update(['face_enrolled' => true]);
                    }
                }
            });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn([
                'device_privilege',
                'device_verification_type',
                'face_enrolled',
            ]);
        });
    }
};
