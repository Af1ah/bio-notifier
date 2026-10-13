<?php

use App\Http\Controllers\Api\DeviceControlController;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

Route::get('/user', function (Request $request) {
    return $request->user();
})->middleware('auth:sanctum');

Route::prefix('devices/{device}')->group(function () {
    Route::match(['get', 'post'], 'unlock', [DeviceControlController::class, 'unlock'])->name('api.devices.unlock');
    Route::match(['get', 'post'], 'lock', [DeviceControlController::class, 'lock'])->name('api.devices.lock');
    Route::match(['get', 'post'], 'normalize', [DeviceControlController::class, 'normalize'])->name('api.devices.normalize');
    Route::get('status', [DeviceControlController::class, 'status'])->name('api.devices.status');
});


// Bridge pairing is public only with a short-lived, single-use site pairing code.
Route::post('/bridges/pair', [\App\Http\Controllers\Api\SiteBridgeController::class, 'pair'])->middleware('throttle:6,1');
Route::prefix('bridge-internal')->middleware(\App\Http\Middleware\BridgeGatewayAuth::class)->group(function () {
    Route::post('auth', [\App\Http\Controllers\Api\SiteBridgeController::class, 'authenticate']);
    Route::post('heartbeat', [\App\Http\Controllers\Api\SiteBridgeController::class, 'heartbeat']);
});
