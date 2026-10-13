<?php

use App\Http\Controllers\Api\Attendance\CDataController;
use App\Http\Controllers\Api\Attendance\DeviceCmdController;
use App\Http\Controllers\Api\Attendance\GetRequestController;
use App\Http\Middleware\IdentifyTenantByDeviceSN;
use App\Http\Middleware\InitializeTenancyByShortname;
use App\Models\User;
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
        'name' => 'Biomatrix',
        'short_name' => 'Biomatrix',
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

Route::group([
    'prefix' => 'iclock',
    'middleware' => [IdentifyTenantByDeviceSN::class],
], function () {
    Route::match(['get', 'post'], 'cdata', CDataController::class)->name('cdata');
    Route::match(['get', 'post'], 'cdata.aspx', CDataController::class);
    Route::get('getrequest', GetRequestController::class)->name('getrequest');
    Route::get('getrequest.aspx', GetRequestController::class);
    Route::match(['get', 'post'], 'devicecmd', DeviceCmdController::class)->name('devicecmd');
    Route::match(['get', 'post'], 'devicecmd.aspx', DeviceCmdController::class);
    Route::match(['get', 'post'], 'test', fn () => response('OK'))->name('test');
});

Route::get('/{tenant}/impersonate', function () {
    $tenant = tenant();

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
    $user = User::where('privilege', 14)->first();
    if (! $user) {
        $user = User::create([
            'name' => 'Admin',
            'email' => 'admin@zkteco.local',
            'password' => Hash::make(Str::random(16)),
            'role' => 'admin',
            'privilege' => 14,
            'pin' => (string) rand(100000000, 999999999),
        ]);
    }

    Auth::guard('web')->login($user);

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

    $user = User::where('privilege', 14)->first();
    if (! $user) {
        $user = User::create([
            'name' => 'Admin',
            'email' => 'admin@zkteco.local',
            'password' => Hash::make(Str::random(16)),
            'role' => 'admin',
            'privilege' => 14,
            'pin' => (string) rand(100000000, 999999999),
        ]);
    }

    Auth::guard('web')->login($user);

    return redirect('/admin');
})->middleware(['web', InitializeTenancyByDomain::class]);

// Redirect /{tenant} to /{tenant}/admin automatically
Route::get('/{tenant}', function ($tenant) {
    return redirect('/'.$tenant.'/admin');
})->where('tenant', '^(?!master|manifest\.json|iclock|magic-login|api|livewire|_debugbar)[^/]+$');

Route::get('/{tenant}/bridge-device/{id}/open', function (\Illuminate\Http\Request $request, string $id) {
    if (!auth()->check()) {
        $request->session()->put('url.intended', $request->fullUrl());
        return redirect()->route('filament.tenant.auth.login', ['tenant'=>tenant('shortname') ?: tenant('id')]);
    }
    abort_unless(auth()->check() && ((int)auth()->user()->privilege === 14 || auth()->user()->role === 'admin'), 403);
    $device = \App\Models\Device::findOrFail($id);
    abort_unless($device->vendor === 'matrix' && $device->connection_mode === 'remote', 404);
    return redirect()->away(app(\App\Services\Bridge\BridgeGateway::class)->webUrl($device));
})->middleware(['web', \App\Http\Middleware\InitializeTenancyByShortname::class])->whereNumber('id')->name('bridge.device.web');
