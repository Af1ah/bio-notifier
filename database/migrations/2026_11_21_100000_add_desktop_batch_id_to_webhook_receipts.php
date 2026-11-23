<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('webhook_receipts', function (Blueprint $table) {
            $table->uuid('desktop_batch_id')->nullable();
            $table->unique(['organisation_id', 'desktop_batch_id'], 'webhook_receipts_desktop_batch');
        });
    }

    public function down(): void
    {
        Schema::table('webhook_receipts', function (Blueprint $table) {
            $table->dropUnique('webhook_receipts_desktop_batch');
            $table->dropColumn('desktop_batch_id');
        });
    }
};
