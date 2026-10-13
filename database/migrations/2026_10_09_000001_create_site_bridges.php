<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
return new class extends Migration {
    public function up(): void {
        Schema::create('site_bridges', function (Blueprint $table) {
            $table->uuid('id')->primary(); $table->string('tenant_id')->index(); $table->string('name');
            $table->string('pairing_hash', 64)->nullable()->unique(); $table->timestamp('pairing_expires_at')->nullable();
            $table->text('public_key')->nullable(); $table->string('hardware_id', 64)->nullable();
            $table->timestamp('last_seen_at')->nullable(); $table->timestamp('revoked_at')->nullable();
            $table->timestamps();
        });
    }
    public function down(): void { Schema::dropIfExists('site_bridges'); }
};
