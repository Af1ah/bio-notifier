<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class LogAllRequests
{
    /**
     * Handle an incoming request.
     *
     * @param  Closure(Request): (Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        if ($request->is('api/bridges/*', 'api/bridge-internal/*')) {
            // Do not log pairing codes, gateway credentials, or identity proofs.
            return $next($request);
        }
        \Illuminate\Support\Facades\Log::channel('single')->info('INCOMING HTTP REQUEST:', [
            'path' => $request->path(),
            'method' => $request->method(),
            'ip' => $request->ip(),
        ]);
        return $next($request);
    }
}
