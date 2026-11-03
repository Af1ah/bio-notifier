<?php

namespace App\Http\Middleware;

use App\Services\EmployeePortal\PortalAccess;
use Closure;
use Illuminate\Http\Request;

class RequireEmployeePasswordChange
{
    public function handle(Request $request, Closure $next)
    {
        $employee = PortalAccess::employee(allowPasswordChange: true);
        if ($employee->requires_password_change && ! $request->routeIs('filament.employee.pages.change-password', 'filament.employee.auth.logout')) {
            return redirect()->route('filament.employee.pages.change-password', ['tenant' => tenant('shortname')]);
        }

        return $next($request);
    }
}
