<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('shift_rule_revisions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('schedule_id')->constrained()->cascadeOnDelete();
            $table->unsignedInteger('revision');
            $table->date('effective_from');
            $table->date('effective_to')->nullable();
            $table->time('start_time');
            $table->time('end_time');
            $table->unsignedTinyInteger('end_day_offset')->default(0);
            $table->unsignedSmallInteger('arrival_grace_minutes')->default(15);
            $table->unsignedSmallInteger('departure_grace_minutes')->default(15);
            $table->unsignedSmallInteger('earliest_arrival_minutes')->default(60);
            $table->unsignedSmallInteger('half_day_minutes')->default(120);
            $table->unsignedSmallInteger('full_day_minutes')->default(480);
            $table->string('punch_method')->default('first_last');
            $table->string('overtime_basis')->default('after_required_hours');
            $table->unsignedSmallInteger('overtime_minimum_minutes')->default(30);
            $table->boolean('auto_checkout_enabled')->default(false);
            $table->time('auto_checkout_time')->nullable();
            $table->unsignedTinyInteger('auto_checkout_day_offset')->nullable();
            $table->unsignedSmallInteger('checkout_cutoff_minutes')->default(180);
            $table->json('snapshot')->nullable();
            $table->timestamps();

            $table->unique(['schedule_id', 'revision']);
            $table->index(['schedule_id', 'effective_from', 'effective_to']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('shift_rule_revisions');
    }
};
