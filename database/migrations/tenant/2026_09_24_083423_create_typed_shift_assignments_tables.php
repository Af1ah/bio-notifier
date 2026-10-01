<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        foreach ([
            'user_shift_assignments' => 'user_id',
            'branch_shift_assignments' => 'branch_id',
            'department_shift_assignments' => 'department_id',
            'task_group_shift_assignments' => 'task_group_id',
        ] as $tableName => $ownerColumn) {
            Schema::create($tableName, function (Blueprint $table) use ($ownerColumn) {
                $table->id();
                $table->foreignId($ownerColumn)->constrained()->cascadeOnDelete();
                $table->foreignId('shift_assignment_set_id')->constrained()->cascadeOnDelete();
                $table->timestamps();
                $table->unique([$ownerColumn, 'shift_assignment_set_id']);
            });
        }

        Schema::create('default_shift_assignments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('shift_assignment_set_id')->constrained()->cascadeOnDelete();
            $table->date('effective_from');
            $table->date('effective_to')->nullable();
            $table->boolean('active')->default(true);
            $table->timestamps();
            $table->index(['active', 'effective_from', 'effective_to']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('default_shift_assignments');
        Schema::dropIfExists('task_group_shift_assignments');
        Schema::dropIfExists('department_shift_assignments');
        Schema::dropIfExists('branch_shift_assignments');
        Schema::dropIfExists('user_shift_assignments');
    }
};
