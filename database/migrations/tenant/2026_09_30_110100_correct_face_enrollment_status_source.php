<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        // eBio's verification type (for example, "Finger or Face or Card") is
        // a policy, not proof that a face template exists. Retain a positive
        // value only when an actual template was received through ADMS.
        DB::table('users')
            ->select(['id', 'face_templates'])
            ->orderBy('id')
            ->chunkById(200, function ($users): void {
                foreach ($users as $user) {
                    $templates = json_decode((string) $user->face_templates, true);

                    DB::table('users')
                        ->where('id', $user->id)
                        ->update([
                            'face_enrolled' => is_array($templates) && $templates !== [] ? true : null,
                        ]);
                }
            });
    }

    public function down(): void
    {
        // The prior inferred values cannot be restored reliably.
    }
};
