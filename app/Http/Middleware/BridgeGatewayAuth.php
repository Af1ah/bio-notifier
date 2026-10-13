<?php
namespace App\Http\Middleware;
use Closure;
use Illuminate\Http\Request;
class BridgeGatewayAuth {
    public function handle(Request $request, Closure $next) {
        $key = config('bridge.gateway_key');
        abort_unless(is_string($key) && strlen($key)>=32 && hash_equals($key,(string)$request->header('X-Bridge-Gateway-Key')),403);
        return $next($request);
    }
}
