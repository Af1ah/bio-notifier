<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class InitializeTenancyByShortname
{
    /**
     * Handle an incoming request.
     *
     * @param  Closure(Request): (Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $route = $request->route();
        
        if ($route && $shortname = $route->parameter('tenant')) {
            $tenant = \App\Models\Organisation::where('shortname', $shortname)->orWhere('id', $shortname)->first();
            
            if ($tenant) {
                tenancy()->initialize($tenant);
                \Illuminate\Support\Facades\URL::defaults(['tenant' => $tenant->shortname ?? $tenant->id]);
                // Remove the parameter so controller doesn't need to accept it
                $route->forgetParameter('tenant');
            } else {
                abort(404, 'Tenant not found');
            }
        }

        return $next($request);
    }
}
