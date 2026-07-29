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
        \Illuminate\Support\Facades\Log::channel('single')->info('INCOMING HTTP REQUEST:', [
            'url' => $request->fullUrl(),
            'method' => $request->method(),
            'ip' => $request->ip(),
            'headers' => $request->headers->all(),
            'payload' => $request->all(),
            'content' => $request->getContent(),
        ]);
        return $next($request);
    }
}
