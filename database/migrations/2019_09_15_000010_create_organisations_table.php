<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class CreateOrganisationsTable extends Migration
{
    public function up(): void
    {
        Schema::create('organisations', function (Blueprint $table) {
            $table->string('id')->primary();
            
            $table->string('name')->nullable();
            $table->string('shortname')->unique()->nullable();
            $table->string('db_name')->unique()->nullable();
            $table->string('logo')->nullable();
            $table->string('brand_color')->nullable();
            $table->string('email')->nullable();
            $table->string('phone')->nullable();

            $table->timestamps();
            $table->json('data')->nullable();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('organisations');
    }
}
