<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('leave_range_requests', function (Blueprint $table) {
            $table->id();
            $table->uuid('request_key')->unique();
            $table->string('payload_hash', 64);
            $table->unsignedBigInteger('created_by_user_id');
            $table->unsignedBigInteger('user_id')->index();
            $table->json('result');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('leave_range_requests');
    }
};
