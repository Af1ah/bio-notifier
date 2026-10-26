<?php

use App\Casts\ProtectedArray;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public $withinTransaction = false;

    public function up(): void
    {
        foreach (['gateway_command_submissions' => ['parameters']] as $table => $columns) {
            if (! Schema::hasTable($table)) {
                continue;
            }
            DB::table($table)->orderBy('id')->chunkById(100, function ($rows) use ($table, $columns) {
                foreach ($rows as $row) {
                    DB::transaction(function () use ($row, $table, $columns) {
                        $row = DB::table($table)->where('id', $row->id)->lockForUpdate()->first();
                        if (! $row) {
                            return;
                        }
                        $values = [];
                        foreach ($columns as $column) {
                            $raw = $row->{$column};
                            if ($raw !== null && ! isset(json_decode($raw, true)['_encrypted_v1'])) {
                                $values[$column] = ProtectedArray::encode(ProtectedArray::decode($raw));
                            }
                        }
                        if ($values) {
                            DB::table($table)->where('id', $row->id)->update($values);
                        }
                    });
                }
            });
        }
    }

    public function down(): void
    { /* Never decrypt credentials during rollback. Legacy data remains readable. */
    }
};
