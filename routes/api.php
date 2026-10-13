<?php

use App\Http\Controllers\EbioWebhookController;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

Route::get('/user', function (Request $request) {
    return $request->user();
})->middleware('auth:sanctum');

Route::post('/ebio/webhook/{token}', [EbioWebhookController::class, 'handle'])->middleware('throttle:120,1');

Route::post('/internal/v1/device-events', \App\Http\Controllers\GatewayEventController::class);
