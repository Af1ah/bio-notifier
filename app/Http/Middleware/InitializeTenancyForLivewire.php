<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use App\Models\Organisation;

class InitializeTenancyForLivewire
{
    public function handle(Request $request, Closure $next)
    {
        if ($request->is('livewire/*')) {
            $host = $request->getHost();
            $centralDomain = env('CENTRAL_DOMAIN', 'ariise.cloud');
            
            $tenant = null;

            // 1. Try Domain-based tenancy
            if ($host !== 'localhost' && $host !== $centralDomain && !str_ends_with($host, '.test')) {
                $domain = \Stancl\Tenancy\Database\Models\Domain::where('domain', $host)->first();
                if ($domain) {
                    $tenant = $domain->tenant;
                }
            }

            // 2. Try Path-based tenancy (fallback for localhost or central domain)
            if (!$tenant) {
                $referer = $request->header('referer');
                if ($referer) {
                    $path = parse_url($referer, PHP_URL_PATH);
                    // e.g. /secumax/admin -> secumax
                    $pathParts = explode('/', trim($path, '/'));
                    if (count($pathParts) > 0 && $pathParts[0] !== 'master' && $pathParts[0] !== 'livewire') {
                        $shortname = $pathParts[0];
                        $tenant = \App\Models\Organisation::where('shortname', $shortname)->first();
                    }
                }
            }
            
            if ($tenant) {
                tenancy()->initialize($tenant);
                \Illuminate\Support\Facades\URL::defaults(['tenant' => $tenant->shortname ?? $tenant->id]);
                
                // Ensure essential storage directories exist for this tenant
                $directories = [
                    storage_path('framework/cache'),
                    storage_path('framework/views'),
                    storage_path('framework/sessions'),
                    storage_path('app/livewire-tmp'),
                    storage_path('app/public'),
                ];
                foreach ($directories as $dir) {
                    if (!is_dir($dir)) {
                        @mkdir($dir, 0755, true);
                    }
                }
            }
        }

        return $next($request);
    }
}
