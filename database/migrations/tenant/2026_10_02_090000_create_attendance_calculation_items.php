<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('attendance_calculation_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('attendance_calculation_run_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->date('work_date');
            $table->unique(['attendance_calculation_run_id', 'user_id', 'work_date'], 'calculation_item_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('attendance_calculation_items');
    }
};
