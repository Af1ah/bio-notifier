<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('device_access_settings', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('device_id')->constrained()->cascadeOnDelete();
            $table->string('kind', 16);
            $table->unsignedInteger('number');
            $table->string('name', 100);
            $table->string('origin', 16)->default('device');
            $table->text('desired');
            $table->text('reported')->nullable();
            $table->timestamp('reported_at')->nullable();
            $table->unsignedBigInteger('binding_id')->nullable();
            $table->unsignedInteger('binding_version')->nullable();
            $table->unsignedInteger('revision')->default(1);
            $table->unsignedInteger('submitted_revision')->nullable();
            $table->uuid('command_id')->nullable()->unique();
            $table->timestamps();
            $table->unique(['device_id', 'kind', 'number']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('device_access_settings');
    }
};
