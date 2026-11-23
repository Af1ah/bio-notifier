<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('frappe_settings', fn (Blueprint $table) => $table->timestamp('cooldown_until')->nullable());
        Schema::table('frappe_checkin_deliveries', function (Blueprint $table) {
            $table->string('outcome', 12)->nullable();
            $table->timestamp('last_attempt_at')->nullable()->index();
        });
    }

    public function down(): void
    {
        Schema::table('frappe_checkin_deliveries', function (Blueprint $table) {
            $table->dropIndex(['last_attempt_at']);
            $table->dropColumn(['outcome', 'last_attempt_at']);
        });
        Schema::table('frappe_settings', fn (Blueprint $table) => $table->dropColumn('cooldown_until'));
    }
};
