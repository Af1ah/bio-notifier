<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('gateway_command_submissions', function (Blueprint $t) {
            $t->json('parameters')->nullable()->after('operation');
        });
    }

    public function down(): void
    {
        Schema::table('gateway_command_submissions', function (Blueprint $t) {
            $t->dropColumn('parameters');
        });
    }
};
