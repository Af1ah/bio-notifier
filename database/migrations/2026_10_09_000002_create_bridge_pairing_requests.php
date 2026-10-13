<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
return new class extends Migration {
    public function up(): void {
        Schema::create('bridge_pairing_requests', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('site_bridge_id')->constrained('site_bridges')->cascadeOnDelete();
            $table->string('hardware_id', 64);
            $table->text('public_key');
            $table->string('status', 16)->default('pending');
            $table->timestamp('expires_at');
            $table->timestamp('decided_at')->nullable();
            $table->string('decided_by')->nullable();
            $table->timestamps();
            $table->index(['site_bridge_id', 'status']);
        });
    }
    public function down(): void { Schema::dropIfExists('bridge_pairing_requests'); }
};
