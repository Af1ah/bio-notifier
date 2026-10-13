<?php

namespace App\Services;

use App\Models\Organisation;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\URL;

class QuickLoginService
{
    public function issue(Organisation $tenant): string
    {
        $actor = Auth::guard('admin')->user();
        abort_unless($actor && $actor->role === 'master', 403);
        $token = bin2hex(random_bytes(32));
        $expires = now()->addMinutes(5);
        DB::connection(config('tenancy.database.central_connection'))->table('quick_login_grants')->insert([
            'token_hash' => hash('sha256', $token), 'actor_id' => $actor->id, 'organisation_id' => $tenant->id,
            'expires_at' => $expires, 'created_at' => now(), 'updated_at' => now(),
        ]);

        return URL::temporarySignedRoute('tenant.impersonate', $expires, ['tenant' => $tenant->shortname ?? $tenant->id, 'grant' => $token]);
    }

    public function consume(Organisation $tenant, ?string $token): void
    {
        abort_unless(is_string($token) && preg_match('/^[a-f0-9]{64}$/', $token), 403);
        DB::connection(config('tenancy.database.central_connection'))->transaction(function ($db) use ($tenant, $token) {
            $grant = $db->table('quick_login_grants')->where('token_hash', hash('sha256', $token))->lockForUpdate()->first();
            abort_unless($grant && $grant->organisation_id === $tenant->id && ! $grant->used_at && now()->lt($grant->expires_at), 403, 'Expired or used login link.');
            abort_unless($db->table('users')->where('id', $grant->actor_id)->where('role', 'master')->exists(), 403, 'Master access was revoked.');
            $db->table('quick_login_grants')->where('id', $grant->id)->update(['used_at' => now(), 'updated_at' => now()]);
        }, 3);
    }
}
