<?php

use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    return redirect('/admin');
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
    $user = \App\Models\User::first();
    
    if (! $user) {
        $user = \App\Models\User::create([
            'name' => 'Admin',
            'email' => 'admin@zkteco.local',
            'password' => \Illuminate\Support\Facades\Hash::make(\Illuminate\Support\Str::random(16)),
            'role' => 'admin',
            'privilege' => 14,
            'pin' => '1',
        ]);
    }
    
    \Illuminate\Support\Facades\Auth::guard('web')->login($user);
    $tenantKey = tenant('shortname') ?: tenant('id');
    return redirect('/' . $tenantKey . '/admin');
})->name('tenant.impersonate')->middleware(['web', \App\Http\Middleware\InitializeTenancyByShortname::class, 'signed']);
