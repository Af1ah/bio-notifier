<?php

namespace App\Services\Backups;

use App\Models\OrganisationBackupRun;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;

final class GoogleDrive
{
    private const API = 'https://www.googleapis.com/drive/v3';

    private const UPLOAD = 'https://www.googleapis.com/upload/drive/v3/files';

    private const SCOPE = 'https://www.googleapis.com/auth/drive.file';

    public static function configured(): bool
    {
        return filled(config('backups.google_client_id')) && filled(config('backups.google_client_secret'))
            && filled(config('backups.google_redirect_uri'));
    }

    public function authorizationUrl(string $state, string $verifier): string
    {
        return 'https://accounts.google.com/o/oauth2/v2/auth?'.http_build_query([
            'client_id' => config('backups.google_client_id'),
            'redirect_uri' => config('backups.google_redirect_uri'),
            'response_type' => 'code',
            'scope' => self::SCOPE,
            'access_type' => 'offline',
            'prompt' => 'consent select_account',
            'state' => $state,
            'code_challenge' => rtrim(strtr(base64_encode(hash('sha256', $verifier, true)), '+/', '-_'), '='),
            'code_challenge_method' => 'S256',
        ], '', '&', PHP_QUERY_RFC3986);
    }

    public function connect(string $code, string $verifier): array
    {
        $tokens = $this->token([
            'grant_type' => 'authorization_code',
            'code' => $code,
            'code_verifier' => $verifier,
            'redirect_uri' => config('backups.google_redirect_uri'),
        ]);
        $refresh = $tokens['refresh_token'] ?? null;
        $scope = $tokens['scope'] ?? null;
        if (! is_string($refresh) || $refresh === '' || ($scope !== null && (! is_string($scope) || ! in_array(self::SCOPE, explode(' ', $scope), true)))) {
            throw new BackupTransferException('drive_authorization', false);
        }

        $response = $this->send(fn () => $this->request($tokens['access_token'])->get(self::API.'/about', [
            'fields' => 'user(permissionId,displayName)',
        ]), 'drive_authorization');
        $id = $response->json('user.permissionId');
        $name = $response->json('user.displayName');
        if (! is_string($id) || $id === '' || strlen($id) > 255) {
            throw new BackupTransferException('drive_authorization', false);
        }

        return [$id, is_string($name) && $name !== '' ? mb_substr($name, 0, 255) : null, $refresh];
    }

    public function accessToken(string $refreshToken): string
    {
        return $this->token(['grant_type' => 'refresh_token', 'refresh_token' => $refreshToken])['access_token'];
    }

    public function generateId(string $accessToken): string
    {
        $response = $this->send(fn () => $this->request($accessToken)->get(self::API.'/files/generateIds', [
            'count' => 1,
            'space' => 'drive',
        ]), 'upload_failed');
        $id = $response->json('ids.0');
        if (! is_string($id) || ! preg_match('/\A[A-Za-z0-9_-]{1,255}\z/', $id)) {
            throw new BackupTransferException('upload_failed');
        }

        return $id;
    }

    public function verified(string $accessToken, OrganisationBackupRun $run): bool
    {
        $response = $this->send(fn () => $this->request($accessToken)->get(
            self::API.'/files/'.rawurlencode($run->drive_file_id), ['fields' => 'id,size,md5Checksum,trashed']
        ), 'verification_unavailable', [404]);
        if ($response->status() === 404) {
            return false;
        }

        $checksum = $response->json('md5Checksum');
        $size = $response->json('size');
        if ($response->json('id') !== $run->drive_file_id || $response->json('trashed') !== false
            || ! is_string($checksum) || ! hash_equals($run->checksum, strtolower($checksum))
            || (string) $run->bytes !== (is_int($size) || is_string($size) ? (string) $size : '')) {
            throw new BackupTransferException('verification_failed', false);
        }

        return true;
    }

    public function upload(string $accessToken, OrganisationBackupRun $run, string $file, string $filename): void
    {
        $start = $this->send(fn () => $this->request($accessToken)->withHeaders([
            'X-Upload-Content-Type' => 'application/sql',
            'X-Upload-Content-Length' => (string) $run->bytes,
        ])->post(self::UPLOAD.'?uploadType=resumable', [
            'id' => $run->drive_file_id,
            'name' => $filename,
            'mimeType' => 'application/sql',
        ]), 'upload_failed', [409]);
        if ($start->status() === 409) {
            return;
        }

        $location = $start->header('Location');
        if (! is_string($location) || ! preg_match('~\Ahttps://www\.googleapis\.com/upload/drive/v3/files\?[^\x00-\x20\x7f#]+\z~', $location)) {
            throw new BackupTransferException('upload_failed');
        }

        $stream = fopen($file, 'rb');
        if ($stream === false) {
            throw new BackupTransferException('dump_failed');
        }

        try {
            $result = $this->send(fn () => $this->request($accessToken)->timeout(360)
                ->withHeader('Content-Length', (string) $run->bytes)->withBody($stream, 'application/sql')->put($location), 'upload_failed', [308]);
            if ($result->status() === 308) {
                throw new BackupTransferException('upload_failed');
            }
        } finally {
            if (is_resource($stream)) {
                fclose($stream);
            }
        }
    }

    private function token(array $grant): array
    {
        if (! self::configured()) {
            throw new BackupTransferException('drive_authorization', false);
        }

        $response = $this->send(fn () => $this->request()->asForm()->post('https://oauth2.googleapis.com/token', $grant + [
            'client_id' => config('backups.google_client_id'),
            'client_secret' => config('backups.google_client_secret'),
        ]), 'drive_authorization', [400]);
        $token = $response->json('access_token');
        if (! is_string($token) || $token === '') {
            throw new BackupTransferException('drive_authorization', false);
        }

        return $response->json();
    }

    private function request(?string $accessToken = null): PendingRequest
    {
        $request = Http::acceptJson()->withoutRedirecting()->connectTimeout(5)->timeout(25)->withOptions(['verify' => true]);

        return $accessToken === null ? $request : $request->withToken($accessToken);
    }

    private function send(callable $request, string $reason, array $allowed = []): Response
    {
        try {
            $response = $request();
        } catch (ConnectionException) {
            throw new BackupTransferException($reason);
        }

        if (! $response->successful() && ! in_array($response->status(), $allowed, true)) {
            $status = $response->status();
            $googleReason = $response->json('error.errors.0.reason');
            if ($googleReason === 'storageQuotaExceeded') {
                throw new BackupTransferException('drive_quota', false);
            }

            $rateLimited = in_array($googleReason, ['rateLimitExceeded', 'userRateLimitExceeded'], true);
            if (! $rateLimited && in_array($status, [401, 403], true)) {
                throw new BackupTransferException('drive_authorization', false);
            }

            throw new BackupTransferException($reason, $rateLimited || $status === 408 || $status === 429 || $status >= 500);
        }

        return $response;
    }
}
