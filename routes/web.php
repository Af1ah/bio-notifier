<?php

use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    return redirect('/master');
});

use App\Http\Controllers\Api\Attendance\CDataController;
use App\Http\Controllers\Api\Attendance\DeviceCmdController;
use App\Http\Controllers\Api\Attendance\GetRequestController;
use App\Http\Controllers\Api\Attendance\MatrixController;

Route::group([
    'prefix' => 'iclock',
    'middleware' => [\App\Http\Middleware\IdentifyTenantByDeviceSN::class],
], function () {
    Route::match(['get', 'post'], 'cdata', CDataController::class)->name('cdata');
    Route::match(['get', 'post'], 'cdata.aspx', CDataController::class);
    
    Route::get('getrequest', GetRequestController::class)->name('getrequest');
    Route::get('getrequest.aspx', GetRequestController::class);
    
    Route::match(['get', 'post'], 'devicecmd', DeviceCmdController::class)->name('devicecmd');
    Route::match(['get', 'post'], 'devicecmd.aspx', DeviceCmdController::class);
    
    Route::match(['get', 'post'], 'test', fn () => response('OK'))->name('test');
});

// Matrix Device Routes
Route::post('login', [MatrixController::class, 'login']);
Route::match(['get', 'post'], 'matrix/{path?}', [MatrixController::class, 'handle'])->where('path', '.*');

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
    $user = \App\Models\User::where('privilege', 14)->first();
    if (! $user) {
        $user = \App\Models\User::create([
            'name' => 'Admin',
            'email' => 'admin@zkteco.local',
            'password' => \Illuminate\Support\Facades\Hash::make(\Illuminate\Support\Str::random(16)),
            'role' => 'admin',
            'privilege' => 14,
            'pin' => (string) rand(100000000, 999999999),
        ]);
    }
    
    \Illuminate\Support\Facades\Auth::guard('web')->login($user);
    return redirect('/' . $tenant->shortname . '/admin');
})->name('tenant.impersonate')->middleware(['web', \App\Http\Middleware\InitializeTenancyByShortname::class, 'signed']);

Route::get('/magic-login', function () {
    $payload = request()->query('payload');
    if (!$payload) {
        abort(403, 'Missing impersonation payload.');
    }

    try {
        $data = decrypt($payload);
    } catch (\Exception $e) {
        abort(403, 'Invalid impersonation token format.');
    }
    
    if (now()->timestamp > $data['expires_at'] || tenant('id') !== $data['tenant_id']) {
        abort(403, 'Expired or unauthorized impersonation token.');
    }
    
    $user = \App\Models\User::where('privilege', 14)->first();
    if (! $user) {
        $user = \App\Models\User::create([
            'name' => 'Admin',
            'email' => 'admin@zkteco.local',
            'password' => \Illuminate\Support\Facades\Hash::make(\Illuminate\Support\Str::random(16)),
            'role' => 'admin',
            'privilege' => 14,
            'pin' => (string) rand(100000000, 999999999),
        ]);
    }
    
    \Illuminate\Support\Facades\Auth::guard('web')->login($user);
    return redirect('/admin');
})->middleware(['web', \Stancl\Tenancy\Middleware\InitializeTenancyByDomain::class]);
