<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('shift_rule_weekdays', function (Blueprint $table) {
            $table->id();
            $table->foreignId('shift_rule_revision_id')->constrained()->cascadeOnDelete();
            $table->unsignedTinyInteger('weekday');
            $table->unique(['shift_rule_revision_id', 'weekday']);
        });

        Schema::create('shift_breaks', function (Blueprint $table) {
            $table->id();
            $table->foreignId('shift_rule_revision_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->time('start_time');
            $table->unsignedTinyInteger('day_offset')->default(0);
            $table->unsignedSmallInteger('duration_minutes');
            $table->timestamps();
        });

        Schema::create('shift_break_weekdays', function (Blueprint $table) {
            $table->id();
            $table->foreignId('shift_break_id')->constrained('shift_breaks')->cascadeOnDelete();
            $table->unsignedTinyInteger('weekday');
            $table->unique(['shift_break_id', 'weekday']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('shift_break_weekdays');
        Schema::dropIfExists('shift_breaks');
        Schema::dropIfExists('shift_rule_weekdays');
    }
};
