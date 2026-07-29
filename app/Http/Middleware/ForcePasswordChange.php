<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Route;

class ForcePasswordChange
{
    /**
     * Handle an incoming request.
     *
     * @param  \Closure(\Illuminate\Http\Request): (\Symfony\Component\HttpFoundation\Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $user = Auth::user();

        if ($user && $user->requires_password_change) {
            if (!$request->routeIs('filament.admin.auth.profile') && !$request->routeIs('filament.admin.auth.logout')) {
                \Filament\Notifications\Notification::make()
                    ->warning()
                    ->title('Action Required')
                    ->body('You must change your default password to continue.')
                    ->send();

                return redirect()->route('filament.admin.auth.profile');
            }
        }

        return $next($request);
    }
}
