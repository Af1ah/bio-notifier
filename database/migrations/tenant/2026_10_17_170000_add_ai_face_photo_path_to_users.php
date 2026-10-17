<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('users', 'ai_face_photo_path')) {
            Schema::table('users', function (Blueprint $table) {
                $table->string('ai_face_photo_path')->nullable()->after('face_templates');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('users', 'ai_face_photo_path')) {
            Schema::table('users', fn (Blueprint $table) => $table->dropColumn('ai_face_photo_path'));
        }
    }
};
