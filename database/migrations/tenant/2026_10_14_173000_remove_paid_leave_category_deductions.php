<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Preserve only configured unpaid-excess treatment in its policy boundary.
        DB::table('leave_policy_revisions as r')->join('leave_policies as p', 'p.id', '=', 'r.leave_policy_id')
            ->join('leave_types as t', 't.id', '=', 'p.leave_type_id')->where('t.is_paid', true)
            ->select('r.id', 'r.rules', 't.deduction_method', 't.deduction_amount_minor')->orderBy('r.id')->chunkById(100, function ($rows) {
                foreach ($rows as $row) {
                    $rules = json_decode($row->rules, true, 512, JSON_THROW_ON_ERROR);
                    if (! empty($rules['allow_unpaid_excess']) && ! array_key_exists('unpaid_excess_deduction_method', $rules)) {
                        if (! in_array($row->deduction_method, ['salary_day_rate', 'basic_salary_31_day_rate', 'fixed_amount'])) {
                            throw new RuntimeException('Reconcile unpaid excess on policy revision '.$row->id.' before clearing category deductions.');
                        }
                        $rules['unpaid_excess_deduction_method'] = $row->deduction_method;
                        $rules['unpaid_excess_deduction_minor'] = (int) $row->deduction_amount_minor;
                        DB::table('leave_policy_revisions')->where('id', $row->id)->update(['rules' => json_encode($rules, JSON_THROW_ON_ERROR)]);
                    }
                }
            }, 'r.id', 'id');
        Schema::table('leave_types', function (Blueprint $table) {
            $table->string('deduction_method')->nullable()->default(null)->change();
            $table->unsignedBigInteger('deduction_amount_minor')->nullable()->default(null)->change();
        });
        // Do not change timestamps: irrelevant paid metadata must not invalidate reviews.
        DB::table('leave_types')->where('is_paid', true)->update(['deduction_method' => null, 'deduction_amount_minor' => null]);
    }

    public function down(): void
    {
        DB::table('leave_types')->whereNull('deduction_method')->update(['deduction_method' => 'none']);
        DB::table('leave_types')->whereNull('deduction_amount_minor')->update(['deduction_amount_minor' => 0]);
        Schema::table('leave_types', function (Blueprint $table) {
            $table->string('deduction_method')->nullable(false)->default('none')->change();
            $table->unsignedBigInteger('deduction_amount_minor')->nullable(false)->default(0)->change();
        });
    }
};
