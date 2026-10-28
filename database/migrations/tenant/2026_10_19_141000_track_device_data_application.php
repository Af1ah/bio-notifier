<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('device_commands', function (Blueprint $t) {
            $t->json('data_receipt_ids')->nullable();
            $t->boolean('data_applied')->default(false);
        });
    }

    public function down(): void
    {
        Schema::table('device_commands', fn ($t) => $t->dropColumn(['data_receipt_ids', 'data_applied']));
    }
};
