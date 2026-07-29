<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use App\Models\Organisation;
use App\Models\Device;
use Illuminate\Support\Facades\Cache;

class IdentifyTenantByDeviceSN
{
    public function handle(Request $request, Closure $next)
    {
        $sn = $request->query('SN');

        if ($sn) {
            $tenantId = Cache::remember("device_tenant_id_{$sn}", 60, function () use ($sn) {
                $found = null;
                foreach (Organisation::all() as $org) {
                    tenancy()->initialize($org);
                    if (Device::where('serial_number', $sn)->exists()) {
                        $found = $org->id;
                    }
                    tenancy()->end();
                    if ($found) return $found;
                }
                return null;
            });

            if ($tenantId) {
                $tenant = Organisation::find($tenantId);
                if ($tenant) {
                    tenancy()->initialize($tenant);
                }
            }
        }

        return $next($request);
    }
}
