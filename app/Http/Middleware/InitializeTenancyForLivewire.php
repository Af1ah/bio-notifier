<?php

namespace App\Http\Middleware;

use App\Http\Middleware\Concerns\ScopesTenantSession;
use App\Models\Organisation;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\URL;
use Livewire\Mechanisms\HandleComponents\Checksum;
use Stancl\Tenancy\Database\Models\Domain;

class InitializeTenancyForLivewire
{
    use ScopesTenantSession;

    public function handle(Request $request, Closure $next)
    {
        if ($request->is('livewire/*')) {
            $host = $request->getHost();
            $centralDomain = env('CENTRAL_DOMAIN', 'ariise.cloud');

            $tenant = null;
            $verifiedPath = null;
            $signedFileRequest = $request->is('livewire/upload-file*') || $request->is('livewire/preview-file/*');
            if ($signedFileRequest) {
                abort_unless($request->hasValidSignature(), 403, 'Invalid file signature.');
                $scope = $request->route('fileTenant');
                abort_unless(is_string($scope), 403, 'Missing file tenant context.');
                if ($scope !== 'central') {
                    $tenant = Organisation::find($scope);
                    abort_unless($tenant, 403, 'Unknown file tenant context.');
                }
                $referer = $request->header('referer');
                if ($referer) {
                    $path = explode('/', trim(parse_url($referer, PHP_URL_PATH) ?? '', '/'))[0];
                    abort_unless(parse_url($referer, PHP_URL_HOST) === $host && $path === ($tenant?->shortname ?? 'master'), 403, 'File tenant context mismatch.');
                }
                if (! $tenant && tenancy()->initialized) {
                    tenancy()->end();
                }
            }
            if ($request->is('livewire/update')) {
                $components = $request->input('components');
                abort_unless(is_array($components) && count($components) > 0 && count($components) <= 50, 403, 'Invalid component context.');
                foreach ($components as $component) {
                    try {
                        $snapshot = json_decode($component['snapshot'] ?? '', true, 512, JSON_THROW_ON_ERROR);
                        if (! is_array($snapshot) || ! isset($snapshot['checksum'], $snapshot['memo']['path'])) {
                            abort(403);
                        }
                        // Verify the installed Livewire HMAC algorithm before
                        // choosing a database. Let Livewire run its normal
                        // release-token checks after tenant/session setup.
                        $checksum = $snapshot['checksum'];
                        unset($snapshot['checksum']);
                        if (! is_string($checksum) || ! hash_equals(Checksum::generate($snapshot), $checksum)) {
                            abort(403);
                        }
                        $path = explode('/', trim($snapshot['memo']['path'], '/'))[0];
                    } catch (\Throwable $exception) {
                        abort(403, 'Invalid component context.');
                    }
                    if ($verifiedPath !== null && $verifiedPath !== $path) {
                        abort(403, 'Mixed tenant context.');
                    }
                    $verifiedPath = $path;
                }
            }

            // 1. Try Domain-based tenancy
            if (! $signedFileRequest && $host !== 'localhost' && ! filter_var($host, FILTER_VALIDATE_IP) && $host !== $centralDomain && ! str_ends_with($host, '.test')) {
                $domain = Domain::where('domain', $host)->first();
                if ($domain) {
                    $tenant = $domain->tenant;
                }
            }

            // 2. Try Path-based tenancy (fallback for localhost or central domain)
            if (! $tenant && ! $signedFileRequest) {
                $referer = $request->header('referer');
                if ($verifiedPath !== null) {
                    if ($referer) {
                        $refererPath = explode('/', trim(parse_url($referer, PHP_URL_PATH) ?? '', '/'))[0];
                        abort_unless($refererPath === $verifiedPath && parse_url($referer, PHP_URL_HOST) === $host, 403, 'Tenant context mismatch.');
                    }
                    if ($verifiedPath !== 'master') {
                        $tenant = Organisation::where('shortname', $verifiedPath)->first();
                        abort_unless($tenant, 403, 'Unknown tenant context.');
                    }
                    $referer = null; // Never select an update tenant from an unsigned header.
                }
                if ($referer) {
                    $path = parse_url($referer, PHP_URL_PATH);
                    // e.g. /secumax/admin -> secumax
                    $pathParts = explode('/', trim($path, '/'));
                    if (count($pathParts) > 0 && $pathParts[0] !== 'master' && $pathParts[0] !== 'livewire') {
                        $shortname = $pathParts[0];
                        $tenant = Organisation::where('shortname', $shortname)->first();
                    }
                }
            }
            if ($tenant && $verifiedPath !== null) {
                abort_unless($verifiedPath === $tenant->shortname, 403, 'Tenant context mismatch.');
            }

            if ($tenant) {
                tenancy()->initialize($tenant);
                $this->scopeTenantSession();
                URL::defaults(['tenant' => $tenant->shortname ?? $tenant->id]);

                // Ensure essential storage directories exist for this tenant
                $directories = [
                    storage_path('framework/cache'),
                    storage_path('framework/views'),
                    storage_path('framework/sessions'),
                    storage_path('app/livewire-tmp'),
                    storage_path('app/public'),
                ];
                foreach ($directories as $dir) {
                    if (! is_dir($dir)) {
                        @mkdir($dir, 0755, true);
                    }
                }
            }
        }

        return $next($request);
    }
}
