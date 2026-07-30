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
            
            // Check if it's not the master domain (e.g. localhost)
            $masterDomains = ['localhost', 'noti.aflahdev.in'];
            if (!in_array($host, $masterDomains)) {
                // Find tenant by domain
                $domain = \Stancl\Tenancy\Database\Models\Domain::where('domain', $host)->first();
                
                if ($domain && $domain->tenant) {
                    tenancy()->initialize($domain->tenant);
                    \Illuminate\Support\Facades\URL::defaults(['tenant' => $domain->tenant->shortname ?? $domain->tenant->id]);
                    
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
        }

        return $next($request);
    }
}
