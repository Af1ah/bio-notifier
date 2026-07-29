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
        Schema::create('reports', function (Blueprint $table) {
            $table->id();
            $table->string('name')->nullable();
            $table->string('type');
            $table->string('date_range');
            $table->string('group_by')->nullable();
            $table->json('filters')->nullable();
            $table->json('user_ids')->nullable();
            $table->boolean('is_template')->default(true);
            $table->string('status')->default('pending');
            $table->timestamp('last_calculated_at')->nullable();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('reports');
    }
};
