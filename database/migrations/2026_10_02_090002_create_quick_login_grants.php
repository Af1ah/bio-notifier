<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('quick_login_grants', function (Blueprint $table) {
            $table->id();
            $table->string('token_hash', 64)->unique();
            $table->unsignedBigInteger('actor_id');
            $table->string('organisation_id');
            $table->timestamp('expires_at');
            $table->timestamp('used_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('quick_login_grants');
    }
};
