<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('webhook_receipts', function (Blueprint $table) {
            $table->id();
            $table->string('organisation_id');
            $table->text('payload');
            $table->string('payload_hash', 64);
            $table->unique(['organisation_id', 'payload_hash']);
            $table->string('state')->default('pending')->index();
            $table->unsignedInteger('attempts')->default(0);
            $table->string('last_error')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('webhook_receipts');
    }
};
