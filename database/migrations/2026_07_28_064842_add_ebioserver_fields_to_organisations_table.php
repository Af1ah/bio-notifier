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
        Schema::table('organisations', function (Blueprint $table) {
            $table->string('status')->default('active');
            $table->string('ebio_url')->nullable();
            $table->string('ebio_webhook_token')->nullable();
            $table->text('ebio_aes_password')->nullable();
            $table->text('ebio_soap_username')->nullable();
            $table->text('ebio_soap_password')->nullable();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('organisations', function (Blueprint $table) {
            $table->dropColumn([
                'status',
                'ebio_url',
                'ebio_webhook_token',
                'ebio_aes_password',
                'ebio_soap_username',
                'ebio_soap_password'
            ]);
        });
    }
};
