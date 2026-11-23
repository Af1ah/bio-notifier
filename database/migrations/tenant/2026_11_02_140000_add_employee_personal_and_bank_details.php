<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->date('date_of_birth')->nullable();
            $table->string('gender', 32)->nullable();
            $table->string('bank_account_holder')->nullable();
            $table->string('bank_name')->nullable();
            $table->text('bank_account_number')->nullable();
            $table->string('bank_branch')->nullable();
            $table->string('bank_ifsc_code', 32)->nullable();
            $table->string('bank_swift_code', 32)->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn(['date_of_birth', 'gender', 'bank_account_holder', 'bank_name', 'bank_account_number', 'bank_branch', 'bank_ifsc_code', 'bank_swift_code']);
        });
    }
};
