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
        Schema::table('devices', function (Blueprint $table) {
            $table->string('vendor')->default('zkteco')->after('id');
            $table->string('username')->nullable()->after('ip_address');
            $table->string('password')->nullable()->after('username');
            $table->integer('port')->nullable()->after('password');
            $table->string('protocol')->default('http')->after('port');
            
            // Make serial_number nullable because Hikvision doesn't strictly need it in our system to be added manually initially
            $table->string('serial_number')->nullable()->change();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('devices', function (Blueprint $table) {
            $table->dropColumn(['vendor', 'username', 'password', 'port', 'protocol']);
            $table->string('serial_number')->nullable(false)->change();
        });
    }
};
