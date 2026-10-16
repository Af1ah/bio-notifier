<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        DB::table('holiday_eligibility_groups')->orderBy('id')->each(function ($legacy) {
            $taskGroup = DB::table('task_groups')->where('name', $legacy->name)->first();
            $groupId = $taskGroup?->id ?? DB::table('task_groups')->insertGetId(['name' => $legacy->name, 'description' => 'Migrated holiday coverage group.', 'created_at' => now(), 'updated_at' => now()]);
            DB::table('holiday_eligibility_memberships')->where('holiday_eligibility_group_id', $legacy->id)->orderBy('id')->each(function ($membership) use ($groupId) {
                DB::table('task_group_memberships')->insert(['task_group_id' => $groupId, 'user_id' => $membership->user_id, 'starts_on' => $membership->starts_on, 'ends_on' => $membership->ends_on, 'created_by_user_id' => $membership->created_by_user_id, 'ended_by_user_id' => $membership->ended_by_user_id, 'end_reason' => $membership->end_reason, 'created_at' => $membership->created_at, 'updated_at' => $membership->updated_at]);
                DB::table('task_group_user')->updateOrInsert(['task_group_id' => $groupId, 'user_id' => $membership->user_id]);
            });
            DB::table('holiday_eligibility_coverage')->where('holiday_eligibility_group_id', $legacy->id)->orderBy('holiday_id')->each(fn ($coverage) => DB::table('holiday_task_group_coverage')->insertOrIgnore(['holiday_id' => $coverage->holiday_id, 'task_group_id' => $groupId]));
        });
        Schema::dropIfExists('holiday_eligibility_coverage'); Schema::dropIfExists('holiday_eligibility_memberships'); Schema::dropIfExists('holiday_eligibility_groups');
    }
    public function down(): void { /* Legacy duplicate flow is deliberately not restored. */ }
};
