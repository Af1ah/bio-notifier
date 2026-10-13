<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
return new class extends Migration {
    public function up(): void { Schema::table('devices', function (Blueprint $table) {
        $table->string('connection_mode', 16)->default('direct'); $table->uuid('site_bridge_id')->nullable()->index();
        $table->unique(['site_bridge_id','ip_address'],'devices_site_bridge_ip_unique');
    }); }
    public function down(): void { Schema::table('devices', fn (Blueprint $table) => $table->dropColumn(['connection_mode', 'site_bridge_id'])); }
};
