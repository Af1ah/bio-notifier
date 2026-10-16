<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('holiday_eligibility_groups', function (Blueprint $t) {
            $t->id();
            $t->string('name');
            $t->string('kind')->default('custom');
            $t->timestamps();
        });
        Schema::create('holiday_eligibility_memberships', function (Blueprint $t) {
            $t->id();
            $t->foreignId('holiday_eligibility_group_id')->constrained()->restrictOnDelete();
            $t->foreignId('user_id')->constrained()->restrictOnDelete();
            $t->date('starts_on');
            $t->date('ends_on')->nullable();
            $t->unsignedBigInteger('created_by_user_id');
            $t->unsignedBigInteger('ended_by_user_id')->nullable();
            $t->text('end_reason')->nullable();
            $t->timestamps();
            $t->index(['user_id', 'starts_on', 'ends_on'], 'holiday_member_date_index');
        });
        Schema::create('holiday_eligibility_coverage', function (Blueprint $t) {
            $t->foreignId('holiday_id')->constrained()->cascadeOnDelete();
            $t->foreignId('holiday_eligibility_group_id')->constrained()->restrictOnDelete();
            $t->primary(['holiday_id', 'holiday_eligibility_group_id'], 'holiday_eligibility_coverage_pk');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('holiday_eligibility_coverage');
        Schema::dropIfExists('holiday_eligibility_memberships');
        Schema::dropIfExists('holiday_eligibility_groups');
    }
};
