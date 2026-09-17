<?php

use App\Http\Controllers\Api\V1;
use App\Http\Controllers\Internal\EngineHookController;
use Illuminate\Support\Facades\Route;

Route::prefix('v1')->name('api.v1.')->middleware('throttle:api')->group(function (): void {
    Route::post('/auth/login', [V1\AuthController::class, 'login'])->middleware('throttle:login')->name('auth.login');
    Route::get('/health', [V1\SystemController::class, 'health'])->name('health');

    Route::middleware(['auth:sanctum'])->group(function (): void {
        Route::post('/auth/logout', [V1\AuthController::class, 'logout'])->name('auth.logout');
        Route::get('/auth/me', [V1\AuthController::class, 'me'])->name('auth.me');

        Route::get('/dashboard', [V1\DashboardController::class, 'index'])->name('dashboard');
        Route::get('/stream-status', [V1\DashboardController::class, 'status'])->name('stream-status');
        Route::get('/analytics', [V1\DashboardController::class, 'analytics'])->name('analytics');

        Route::get('/streams', [V1\StreamController::class, 'index'])->name('streams.index');
        Route::get('/streams/{session}', [V1\StreamController::class, 'show'])->name('streams.show');
        Route::post('/streams/start', [V1\StreamController::class, 'start'])->middleware('throttle:api-control')->name('streams.start');
        Route::post('/streams/stop', [V1\StreamController::class, 'stop'])->middleware('throttle:api-control')->name('streams.stop');
        Route::get('/streams/{session}/logs', [V1\StreamController::class, 'logs'])->name('streams.logs');

        Route::apiResource('destinations', V1\DestinationController::class);
        Route::post('/destinations/{destination}/test', [V1\DestinationController::class, 'test'])->name('destinations.test');

        Route::get('/stream-keys', [V1\StreamKeyController::class, 'index'])->name('stream-keys.index');
        Route::post('/stream-keys', [V1\StreamKeyController::class, 'store'])->name('stream-keys.store');

        Route::get('/updates/check', [V1\SystemController::class, 'updateCheck'])->name('updates.check');
        Route::post('/updates/install', [V1\SystemController::class, 'updateInstall'])->middleware('throttle:api-control')->name('updates.install');
        Route::get('/system/health', [V1\SystemController::class, 'fullHealth'])->name('system.health');
    });
});

// Internal hooks from the streaming engine (MediaMTX). Authenticated by shared secret.
Route::prefix('internal/engine')->middleware(['engine.secret', 'throttle:engine'])->name('internal.engine.')->group(function (): void {
    Route::post('/auth', [EngineHookController::class, 'auth'])->name('auth');
    Route::post('/ready', [EngineHookController::class, 'ready'])->name('ready');
    Route::post('/not-ready', [EngineHookController::class, 'notReady'])->name('not-ready');
});
