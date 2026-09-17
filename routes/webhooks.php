<?php

use App\Http\Controllers\Webhooks\WebhookController;
use Illuminate\Support\Facades\Route;

Route::prefix('webhooks')->middleware('throttle:webhooks')->name('webhooks.')->group(function (): void {
    Route::match(['get', 'post'], '/youtube', [WebhookController::class, 'youtube'])->name('youtube');
    Route::match(['get', 'post'], '/meta', [WebhookController::class, 'meta'])->name('meta');
    Route::match(['get', 'post'], '/platform/{name}', [WebhookController::class, 'platform'])->name('platform');
});
