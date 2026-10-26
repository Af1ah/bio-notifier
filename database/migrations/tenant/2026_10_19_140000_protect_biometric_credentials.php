<?php

use App\Casts\ProtectedArray;
use App\Services\Devices\PrivateBiometricPhoto;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;

return new class extends Migration
{
    public $withinTransaction = false;

    public function up(): void
    {
        foreach (['users' => ['fingerprints', 'face_templates'], 'gateway_command_outbox' => ['parameters']] as $table => $columns) {
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
                        if ($table === 'users' && filled($row->ai_face_photo_path)) {
                            PrivateBiometricPhoto::protect($row->ai_face_photo_path);
                        }
                    });
                }
            });
        }
        foreach (Storage::disk('local')->allFiles('biometric-photos') as $path) {
            PrivateBiometricPhoto::protect($path);
        }
    }

    /* All former previews and upload orphans remain private and encrypted, too. */
    public function down(): void
    { /* Never decrypt credentials during rollback. Legacy data remains readable. */
    }
};
