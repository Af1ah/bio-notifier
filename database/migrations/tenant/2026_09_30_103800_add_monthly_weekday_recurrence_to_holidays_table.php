<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('holidays', function (Blueprint $table) {
            $table->unsignedTinyInteger('monthly_weekday')->nullable()->after('recurrence');
            $table->string('monthly_pattern')->nullable()->after('monthly_weekday');
            $table->json('monthly_weeks')->nullable()->after('monthly_pattern');
        });
    }

    public function down(): void
    {
        Schema::table('holidays', function (Blueprint $table) {
            $table->dropColumn(['monthly_weekday', 'monthly_pattern', 'monthly_weeks']);
        });
    }
};
