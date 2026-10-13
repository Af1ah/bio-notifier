<?php

use App\Http\Middleware\InitializeTenancyByShortname;
use App\Models\User;
use App\Services\QuickLoginService;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;
use Stancl\Tenancy\Middleware\InitializeTenancyByDomain;

Route::get('/', function () {
    return redirect('/master');
});

Route::get('/manifest.json', function () {
    return response()->json([
        'name' => 'BIO-Notifier',
        'short_name' => 'BIO-Notifier',
        'description' => 'Biometric Attendance & Notification System',
        'start_url' => request()->query('start_url', '/'),
        'display' => 'standalone',
        'background_color' => '#ffffff',
        'theme_color' => '#f59e0b',
        'icons' => [
            [
                'src' => '/icon-v2.svg',
                'sizes' => 'any',
                'type' => 'image/svg+xml',
            ],
            [
                'src' => '/icon-512-v3.png',
                'sizes' => '512x512',
                'type' => 'image/png',
                'purpose' => 'any maskable',
            ],
            [
                'src' => '/icon-192-v3.png',
                'sizes' => '192x192',
                'type' => 'image/png',
                'purpose' => 'any maskable',
            ],
        ],
    ]);
});

Route::get('/{tenant}/impersonate', function () {
    $tenant = tenant();
    app(QuickLoginService::class)->consume($tenant, request()->query('grant'));

    // --- DOMAIN-BASED TENANCY (Commented out for future use) ---
    // $centralDomain = request()->getHost();
    // $port = request()->getPort();
    // $scheme = request()->getScheme();
    // $portSuffix = in_array($port, [80, 443]) ? '' : ':' . $port;
    // $domain = $tenant->domains->first()->domain ?? ($tenant->shortname . '.' . $centralDomain);
    // $tenantUrl = $scheme . '://' . $domain . $portSuffix;
    // $payload = encrypt([
    //     'tenant_id' => $tenant->id,
    //     'expires_at' => now()->addMinutes(1)->timestamp,
    // ]);
    // return redirect($tenantUrl . '/magic-login?payload=' . urlencode($payload));

    // --- PATH-BASED TENANCY (Currently Active) ---
    $user = User::loginAccounts()->orderBy('id')->first();
    if (! $user) {
        $user = User::create([
            'name' => 'Admin',
            'email' => User::DEFAULT_LOGIN_EMAIL,
            'password' => Hash::make(Str::random(16)),
            'role' => 'admin',
            'privilege' => 14,
            'pin' => (string) rand(100000000, 999999999),
        ]);
    }

    $guard = Auth::guard('web');
    $guard->logoutCurrentDevice();
    request()->session()->forget('password_hash_web');
    $guard->login($user);
    request()->session()->regenerate();

    return redirect('/'.$tenant->shortname.'/admin');
})->name('tenant.impersonate')->middleware(['web', InitializeTenancyByShortname::class, 'signed']);

Route::get('/magic-login', function () {
    $payload = request()->query('payload');
    if (! $payload) {
        abort(403, 'Missing impersonation payload.');
    }

    try {
        $data = decrypt($payload);
    } catch (Exception $e) {
        abort(403, 'Invalid impersonation token format.');
    }

    if (now()->timestamp > $data['expires_at'] || tenant('id') !== $data['tenant_id']) {
        abort(403, 'Expired or unauthorized impersonation token.');
    }

    $user = User::loginAccounts()->orderBy('id')->first();
    if (! $user) {
        $user = User::create([
            'name' => 'Admin',
            'email' => User::DEFAULT_LOGIN_EMAIL,
            'password' => Hash::make(Str::random(16)),
            'role' => 'admin',
            'privilege' => 14,
            'pin' => (string) rand(100000000, 999999999),
        ]);
    }

    $guard = Auth::guard('web');
    $guard->logoutCurrentDevice();
    request()->session()->forget('password_hash_web');
    $guard->login($user);
    request()->session()->regenerate();

    return redirect('/admin');
})->middleware(['web', InitializeTenancyByDomain::class]);

// Redirect /{tenant} to /{tenant}/admin automatically
Route::get('/{tenant}', function ($tenant) {
    return redirect('/'.$tenant.'/admin');
})->where('tenant', '^(?!master|manifest\.json|iclock|magic-login|api|livewire|_debugbar).*$');
