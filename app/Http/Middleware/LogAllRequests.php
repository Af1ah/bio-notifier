<?php

namespace App\Http\Middleware;

use App\Services\Diagnostics\RequestEventStore;
use Closure;
use Illuminate\Database\QueryException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
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
        $response = $next($request);
        // Never persist request bodies, headers, URLs or query strings: these
        // include login passwords, session cookies and vendor credentials.
        try {
            app(RequestEventStore::class)->record([
                'method' => $request->method(),
                'route' => $request->route()?->getName(),
                'status' => $response->getStatusCode(),
            ]);
        } catch (QueryException $exception) {
            // Diagnostics must not turn an already accepted request into 500.
            Log::warning('Request diagnostic storage unavailable.');
        }

        return $response;
    }
}
