<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('attendance_days', fn (Blueprint $t) => $t->string('source')->default('device')->index());
        Schema::table('attendance_occurrences', fn (Blueprint $t) => $t->string('source')->default('device')->index());
        Schema::table('employee_payroll_profiles', function (Blueprint $t) {
            $t->date('employment_starts_on')->nullable();
            $t->date('employment_ends_on')->nullable();
        });
        Schema::table('attendance_calculation_runs', function (Blueprint $t) {
            $t->unsignedInteger('skipped')->default(0);
            $t->unsignedInteger('legacy_completed_credit')->default(0);
        });
        Schema::create('manual_attendance_entries', function (Blueprint $t) {
            $t->id();
            $t->foreignId('user_id')->constrained()->restrictOnDelete();
            $t->date('work_date');
            $t->foreignId('schedule_id')->constrained()->restrictOnDelete();
            $t->foreignId('shift_rule_revision_id')->constrained()->restrictOnDelete();
            $t->timestamp('in_at');
            $t->timestamp('out_at');
            $t->text('reason');
            $t->foreignId('created_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $t->timestamps();
            $t->unique(['user_id', 'schedule_id', 'in_at', 'out_at'], 'manual_attendance_unique_interval');
            $t->index(['user_id', 'work_date']);
        });
        Schema::create('attendance_baseline_adoptions', function (Blueprint $t) {
            $t->id();
            $t->foreignId('attendance_day_id')->constrained()->restrictOnDelete();
            $t->unsignedInteger('calculation_revision');
            $t->string('reviewed_input_signature', 64);
            $t->json('original_snapshot');
            $t->json('reviewed_evidence');
            $t->text('reason');
            $t->foreignId('reviewed_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $t->timestamp('reviewed_at');
            $t->unique(['attendance_day_id', 'calculation_revision'], 'attendance_baseline_revision_unique');
        });
        Schema::create('manual_payroll_reviews', function (Blueprint $t) {
            $t->id();
            $t->foreignId('user_id')->constrained()->restrictOnDelete();
            $t->date('period_start');
            $t->date('period_end');
            $t->string('evidence_signature', 64);
            $t->json('snapshot');
            $t->text('reason');
            $t->foreignId('reviewed_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $t->timestamp('reviewed_at');
            $t->unique(['user_id', 'period_start', 'period_end', 'evidence_signature'], 'manual_payroll_review_evidence_unique');
        });
        Schema::create('attendance_calculation_results', function (Blueprint $t) {
            $t->id();
            $t->foreignId('attendance_calculation_run_id')->constrained()->cascadeOnDelete();
            // Deliberately not a user FK: deleting an employee must not delete run evidence.
            $t->unsignedBigInteger('employee_id_snapshot');
            $t->date('work_date');
            $t->string('outcome');
            $t->string('reason')->nullable();
            $t->timestamps();
            $t->unique(['attendance_calculation_run_id', 'employee_id_snapshot', 'work_date'], 'attendance_run_employee_date_unique');
        });
        DB::table('attendance_calculation_items')->orderBy('id')->chunkById(200, function ($items) {
            foreach ($items as $item) {
                DB::table('attendance_calculation_results')->insert(['attendance_calculation_run_id' => $item->attendance_calculation_run_id,
                    'employee_id_snapshot' => $item->user_id, 'work_date' => $item->work_date, 'outcome' => 'completed', 'created_at' => now(), 'updated_at' => now()]);
            }
        });
        foreach (DB::table('attendance_calculation_runs')->get() as $run) {
            $known = DB::table('attendance_calculation_results')->where('attendance_calculation_run_id', $run->id)->count();
            $credit = max(0, $run->completed - $known);
            $changes = ['legacy_completed_credit' => $credit];
            if ($credit && in_array($run->state, ['queued', 'running'])) {
                $changes += ['state' => 'needs_reconciliation', 'last_error' => 'Legacy completed counts lack employee-day details. Original progress was retained; reconcile before resuming.'];
            }
            DB::table('attendance_calculation_runs')->where('id', $run->id)->update($changes);
        }
    }

    public function down(): void
    {
        foreach (['attendance_calculation_results', 'manual_payroll_reviews', 'attendance_baseline_adoptions', 'manual_attendance_entries'] as $table) {
            Schema::dropIfExists($table);
        }
        Schema::table('attendance_calculation_runs', fn (Blueprint $t) => $t->dropColumn(['skipped', 'legacy_completed_credit']));
        Schema::table('employee_payroll_profiles', fn (Blueprint $t) => $t->dropColumn(['employment_starts_on', 'employment_ends_on']));
        Schema::table('attendance_occurrences', fn (Blueprint $t) => $t->dropIndex(['source']));
        Schema::table('attendance_occurrences', fn (Blueprint $t) => $t->dropColumn('source'));
        Schema::table('attendance_days', fn (Blueprint $t) => $t->dropIndex(['source']));
        Schema::table('attendance_days', fn (Blueprint $t) => $t->dropColumn('source'));
    }
};
