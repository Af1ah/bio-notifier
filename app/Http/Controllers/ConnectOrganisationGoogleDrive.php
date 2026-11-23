<?php

namespace App\Http\Controllers;

use App\Filament\Tenant\Pages\Backups;
use App\Models\Organisation;
use App\Models\OrganisationBackupSetting;
use App\Models\User;
use App\Services\Backups\BackupSchedule;
use App\Services\Backups\BackupTransferException;
use App\Services\Backups\GoogleDrive;
use App\Services\Support\TenantTimezone;
use App\Services\Tenancy\TenantContext;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;

final class ConnectOrganisationGoogleDrive
{
    public function start(Request $request, GoogleDrive $drive): RedirectResponse
    {
        abort_unless(Backups::canAccess(), 403);
        abort_unless(GoogleDrive::configured(), 503);

        $state = Str::random(64);
        $verifier = Str::random(64);
        $challenge = [
            'organisation_id' => (string) tenant('id'),
            'user_id' => auth('web')->id(),
            'session_hash' => hash('sha256', $request->session()->getId()),
            'return_path' => Backups::getUrl(isAbsolute: false),
            'verifier' => $verifier,
        ];

        tenancy()->central(fn () => Cache::put('google-drive-oauth:'.$state, $challenge, now()->addMinutes(10)));

        return redirect()->away($drive->authorizationUrl($state, $verifier))
            ->header('Cache-Control', 'no-store')->header('Referrer-Policy', 'no-referrer');
    }

    public function callback(Request $request, GoogleDrive $drive): RedirectResponse
    {
        $state = $request->query('state');
        abort_unless(is_string($state) && preg_match('/\A[A-Za-z0-9]{64}\z/', $state), 403);

        $key = 'google-drive-oauth:'.$state;
        $challenge = Cache::lock($key.':claim', 10)->get(function () use ($key, $request) {
            $challenge = Cache::get($key);
            abort_unless(is_array($challenge), 403);
            $cookie = $request->cookie('bio-notifier-tenant-'.hash('sha256', $challenge['organisation_id']));
            abort_unless(is_string($cookie) && hash_equals($challenge['session_hash'], hash('sha256', $cookie)), 403);

            return Cache::pull($key);
        });
        abort_unless(is_array($challenge), 403);

        $organisation = Organisation::findOrFail($challenge['organisation_id']);
        $admin = TenantContext::run($organisation, fn () => (bool) User::find($challenge['user_id'])?->has_login);
        abort_unless($admin, 403);
        $destination = $challenge['return_path'];
        $code = $request->query('code');
        if (! is_string($code) || $code === '' || strlen($code) > 4096) {
            return $this->back($destination, false);
        }

        try {
            [$account, $name, $refresh] = $drive->connect($code, $challenge['verifier']);
        } catch (BackupTransferException) {
            return $this->back($destination, false);
        }

        $timezone = TenantContext::run($organisation, fn () => TenantTimezone::get());
        $connected = BackupSchedule::lock((string) $organisation->id)->get(function () use ($organisation, $account, $name, $refresh, $timezone) {
            $settings = OrganisationBackupSetting::findOrNew($organisation->id);
            $connection = $settings->drive_account === $account && $settings->connection_id
                ? $settings->connection_id : (string) Str::uuid();
            $settings->fill([
                'organisation_id' => $organisation->id,
                'connection_id' => $connection,
                'drive_account' => $account,
                'drive_name' => $name,
                'refresh_token' => $refresh,
                'backup_time' => $settings->backup_time ?? '02:00',
                'timezone' => $timezone,
            ]);
            $settings->next_run_at = BackupSchedule::next($settings->frequency, $settings->backup_time, $timezone);
            $settings->save();

            return true;
        });

        return $this->back($destination, $connected === true);
    }

    private function back(string $destination, bool $ok): RedirectResponse
    {
        return redirect()->to($destination.($ok ? '' : '?drive=connection_failed'))
            ->header('Cache-Control', 'no-store')->header('Referrer-Policy', 'no-referrer');
    }
}
